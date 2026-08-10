<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The ownership record for every link this module authored.
 *
 * This class is the manual-link guarantee. A link is only ever deleted or
 * re-positioned if it has a row here, and a merchant's hand-picked link never
 * gets one - so it cannot be released, ever, by any rule.
 *
 * Everything is raw SQL through ResourceConnection. The alternative APIs all
 * replace every link of a type on the product
 * (ProductLinkManagementInterface::setProductLinks(),
 * Magento\Catalog\Model\ResourceModel\Product\Link::saveProductLinks()), which
 * is exactly the behaviour this module exists to avoid; and the per-link
 * repository loads a full product model per call, which a catalog-wide sweep
 * cannot afford.
 */
class Ledger
{
    private const TABLE = 'magenx_auto_link_ledger';
    private const LINK_TABLE = 'catalog_product_link';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Links this module owns for a batch of source products.
     *
     * Reads on the unique key (product_id leads), so the IN set is a range scan
     * and link_type_id filters within it.
     *
     * @param int[] $productIds
     * @param int $linkTypeId
     * @return array<int, int[]> sourceProductId => linkedProductId[]
     */
    public function getOwned(array $productIds, int $linkTypeId): array
    {
        $productIds = $this->normalizeIds($productIds);
        if (!$productIds) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['product_id', 'linked_product_id'])
                ->where('link_type_id = ?', $linkTypeId)
                ->where('product_id IN (?)', $productIds)
        );

        $owned = [];
        foreach ($rows as $row) {
            $owned[(int) $row['product_id']][] = (int) $row['linked_product_id'];
        }

        return $owned;
    }

    /**
     * Record the links a rule just wrote.
     *
     * Upserts on MAGENX_AUTO_LINK_LEDGER_PRODUCT_LINKED_TYPE, so a re-run is
     * idempotent and a link can only ever be owned by one rule (the last one to
     * claim it wins, which combined with the deterministic run order makes a run
     * reproducible).
     *
     * @param array<int, array{rule_id:int, product_id:int, linked_product_id:int, link_type_id:int, position:int}> $rows
     * @return int
     */
    public function claim(array $rows): int
    {
        if (!$rows) {
            return 0;
        }

        $connection = $this->resource->getConnection();

        return (int) $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            $rows,
            ['rule_id', 'position']
        );
    }

    /**
     * Drop ledger rows for links that are no longer wanted.
     *
     * @param int $productId
     * @param int $linkTypeId
     * @param int[] $linkedProductIds
     * @return int
     */
    public function release(int $productId, int $linkTypeId, array $linkedProductIds): int
    {
        $linkedProductIds = $this->normalizeIds($linkedProductIds);
        if (!$linkedProductIds) {
            return 0;
        }

        $connection = $this->resource->getConnection();

        return (int) $connection->delete($this->resource->getTableName(self::TABLE), [
            'product_id = ?' => $productId,
            'link_type_id = ?' => $linkTypeId,
            'linked_product_id IN (?)' => $linkedProductIds,
        ]);
    }

    /**
     * Remove every link a rule owns, from the catalog AND from the ledger.
     *
     * Called when a rule is deleted (from the resource model's _beforeDelete, so
     * the catalog rows go before the FK cascade can strip the evidence of who
     * owned them) and when a rule is switched off.
     *
     * Deletes the catalog_product_link rows first: their positions in
     * catalog_product_link_attribute_int cascade away with them, so no second
     * cleanup statement is needed.
     *
     * @param int $ruleId
     * @return int[] the source product ids that were touched, for cache invalidation
     */
    public function releaseRule(int $ruleId): array
    {
        $connection = $this->resource->getConnection();
        $ledgerTable = $this->resource->getTableName(self::TABLE);

        // Collected before the delete, purely so the caller can invalidate the
        // right products' caches afterwards.
        $touched = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from($ledgerTable, ['product_id'])
                ->where('rule_id = ?', $ruleId)
        ));

        if (!$touched) {
            return [];
        }

        // One multi-table DELETE rather than a statement per link: a rule that
        // has run over a large catalog can own tens of thousands of links, and
        // the row-at-a-time version would hold the write lock on
        // catalog_product_link for the whole sweep.
        $linkTable = $this->resource->getTableName(self::LINK_TABLE);
        $connection->query(
            sprintf(
                'DELETE cpl FROM %s AS cpl'
                . ' INNER JOIN %s AS mall'
                . ' ON mall.product_id = cpl.product_id'
                . ' AND mall.linked_product_id = cpl.linked_product_id'
                . ' AND mall.link_type_id = cpl.link_type_id'
                . ' WHERE mall.rule_id = ?',
                $connection->quoteIdentifier($linkTable),
                $connection->quoteIdentifier($ledgerTable)
            ),
            [$ruleId]
        );

        $connection->delete($ledgerTable, ['rule_id = ?' => $ruleId]);

        return $touched;
    }

    /**
     * @param array $ids
     * @return int[]
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
