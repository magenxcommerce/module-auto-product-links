<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Read/write access to the mined co-purchase aggregate.
 *
 * The table is bucketed by month, exactly like Magento's own
 * sales_bestsellers_aggregated_monthly. Bucketing is what lets the miner be
 * incremental and exactly windowed at the same time: a period is DELETEd and
 * rebuilt rather than accumulated blindly, so a run that dies half way through
 * leaves a partial month the next run simply rebuilds - no watermark drift and
 * no double counting.
 */
class CoPurchase
{
    private const TABLE = 'magenx_auto_link_copurchase';
    private const PRUNE_BATCH = 50000;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Whether the sales tables needed for mining are reachable.
     *
     * In a split-database setup sales lives on its own connection and the join
     * against it is not possible from the default one. Mirrors
     * BestSellerProvider's isTableExists() guard: a missing table is a reason to
     * skip quietly, never to throw.
     *
     * @return bool
     */
    public function isMinable(): bool
    {
        try {
            $connection = $this->resource->getConnection();

            return $connection->isTableExists($this->resource->getTableName('sales_order'))
                && $connection->isTableExists($this->resource->getTableName('sales_order_item'));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Drop a whole period so it can be rebuilt from scratch.
     *
     * @param string $period Y-m-01
     * @return int
     */
    public function clearPeriod(string $period): int
    {
        $connection = $this->resource->getConnection();

        return (int) $connection->delete(
            $this->resource->getTableName(self::TABLE),
            ['period = ?' => $period]
        );
    }

    /**
     * Count co-purchased pairs for one slice of orders inside a period.
     *
     * Two things in the WHERE clause carry the whole correctness of the miner:
     *
     * 1. `parent_item_id IS NULL` on BOTH sides. A configurable purchase writes
     *    two sales_order_item rows - the parent (parent_item_id NULL, carrying
     *    the parent product id) and the chosen simple child (parent_item_id set).
     *    Bundles are the same shape. Keeping only the parents removes the double
     *    count AND yields the product the storefront actually links to, since a
     *    link hanging off an invisible variant renders nothing on the product
     *    page. No product_type filter is needed for that, and adding one would
     *    wrongly drop configurables altogether.
     * 2. The symmetric join (`b.product_id <> a.product_id`, not `<`) emits both
     *    A->B and B->A in one pass, which is what makes the read a pure
     *    `product_id IN (...)` seek on the covering index.
     *
     * Accumulating with `orders_count + VALUES(orders_count)` across slices is
     * only safe because the period was cleared first.
     *
     * @param string $period Y-m-01
     * @param string $periodStart inclusive datetime
     * @param string $periodEnd exclusive datetime
     * @param int $orderIdFrom exclusive
     * @param int $orderIdTo inclusive
     * @param int $maxItemsPerOrder orders with more lines than this are skipped
     * @return int
     */
    public function accumulateSlice(
        string $period,
        string $periodStart,
        string $periodEnd,
        int $orderIdFrom,
        int $orderIdTo,
        int $maxItemsPerOrder
    ): int {
        $connection = $this->resource->getConnection();

        $table = $connection->quoteIdentifier($this->resource->getTableName(self::TABLE));
        $orderTable = $connection->quoteIdentifier($this->resource->getTableName('sales_order'));
        $itemTable = $connection->quoteIdentifier($this->resource->getTableName('sales_order_item'));

        // The number of pairs an order contributes grows with the square of its
        // line count, so one 200-line B2B order would contribute ~39,800 pairs
        // and drown the signal from ordinary baskets. Such orders are excluded
        // outright rather than weighted down.
        $sql = sprintf(
            'INSERT INTO %1$s'
            . ' (store_id, period, product_id, linked_product_id, orders_count, updated_at)'
            . ' SELECT a.store_id, ?, a.product_id, b.product_id, COUNT(DISTINCT a.order_id), NOW()'
            . ' FROM %2$s AS o'
            . ' INNER JOIN %3$s AS a ON a.order_id = o.entity_id'
            . ' INNER JOIN %3$s AS b ON b.order_id = o.entity_id'
            . ' WHERE o.created_at >= ? AND o.created_at < ?'
            . ' AND o.entity_id > ? AND o.entity_id <= ?'
            . ' AND o.state <> ?'
            // COALESCE, not a bare comparison: total_item_count is nullable on
            // older/imported orders, and NULL <= 50 is NULL, which would drop
            // those orders from the aggregate silently.
            . ' AND COALESCE(o.total_item_count, 0) <= ?'
            . ' AND a.parent_item_id IS NULL AND a.product_type <> ?'
            . ' AND b.parent_item_id IS NULL AND b.product_type <> ?'
            . ' AND b.product_id <> a.product_id'
            . ' AND b.store_id = a.store_id'
            . ' GROUP BY a.store_id, a.product_id, b.product_id'
            . ' ON DUPLICATE KEY UPDATE'
            . ' orders_count = orders_count + VALUES(orders_count), updated_at = VALUES(updated_at)',
            $table,
            $orderTable,
            $itemTable
        );

        return (int) $connection->query($sql, [
            $period,
            $periodStart,
            $periodEnd,
            $orderIdFrom,
            $orderIdTo,
            \Magento\Sales\Model\Order::STATE_CANCELED,
            $maxItemsPerOrder,
            'grouped',
            'grouped',
        ])->rowCount();
    }

    /**
     * The order-id range and count for one period, used to slice the mining run.
     *
     * @param string $periodStart
     * @param string $periodEnd
     * @return array{min:int, max:int, count:int}
     */
    public function getOrderRange(string $periodStart, string $periodEnd): array
    {
        $connection = $this->resource->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from(
                    $this->resource->getTableName('sales_order'),
                    [
                        'min_id' => new \Zend_Db_Expr('MIN(entity_id)'),
                        'max_id' => new \Zend_Db_Expr('MAX(entity_id)'),
                        'cnt' => new \Zend_Db_Expr('COUNT(*)'),
                    ]
                )
                ->where('created_at >= ?', $periodStart)
                ->where('created_at < ?', $periodEnd)
        );

        return [
            'min' => (int) ($row['min_id'] ?? 0),
            'max' => (int) ($row['max_id'] ?? 0),
            'count' => (int) ($row['cnt'] ?? 0),
        ];
    }

    /**
     * Partners of each source product, strongest first.
     *
     * This is the query the covering index
     * MAGENX_AUTO_LINK_COPURCHASE_STORE_PRODUCT_PERIOD_COUNT exists for:
     * equality on store_id, an IN set on product_id, a range on period, then the
     * summed column - so the whole group-and-sum resolves from the index alone.
     *
     * @param int[] $storeIds
     * @param int[] $productIds
     * @param string $cutoffPeriod Y-m-01
     * @param int $minSupport
     * @return array<int, int[]> productId => linkedProductId[] ordered by support desc
     */
    public function getPartners(array $storeIds, array $productIds, string $cutoffPeriod, int $minSupport): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds || !$storeIds) {
            return [];
        }

        try {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(
                        $this->resource->getTableName(self::TABLE),
                        [
                            'product_id',
                            'linked_product_id',
                            'support' => new \Zend_Db_Expr('SUM(orders_count)'),
                        ]
                    )
                    ->where('store_id IN (?)', $storeIds)
                    ->where('product_id IN (?)', $productIds)
                    ->where('period >= ?', $cutoffPeriod)
                    ->group(['product_id', 'linked_product_id'])
                    ->having('SUM(orders_count) >= ?', $minSupport)
                    ->order('product_id ASC')
                    ->order('support DESC')
            );
        } catch (\Exception $e) {
            // The aggregate has never been built (the miner has not run, or the
            // table is absent on this connection). No partners is the right
            // answer; a thrown exception would take the whole cron down.
            return [];
        }

        $partners = [];
        foreach ($rows as $row) {
            $partners[(int) $row['product_id']][] = (int) $row['linked_product_id'];
        }

        return $partners;
    }

    /**
     * Delete periods that have aged out of the look-back window, in batches so
     * no single statement holds a long lock.
     *
     * @param string $cutoffPeriod Y-m-01
     * @return int
     */
    public function prune(string $cutoffPeriod): int
    {
        $connection = $this->resource->getConnection();

        $sql = sprintf(
            'DELETE FROM %s WHERE period < %s LIMIT %d',
            $connection->quoteIdentifier($this->resource->getTableName(self::TABLE)),
            $connection->quote($cutoffPeriod),
            self::PRUNE_BATCH
        );

        $total = 0;
        do {
            $deleted = (int) $connection->query($sql)->rowCount();
            $total += $deleted;
        } while ($deleted >= self::PRUNE_BATCH);

        return $total;
    }
}
