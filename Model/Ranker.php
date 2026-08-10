<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Orders a rule's candidate pool once per run, so that picking the best N for a
 * given source product is a sort of the small surviving set rather than a
 * re-ranking of the pool.
 *
 * NB the resulting order is written to catalog_product_link_attribute_int as the
 * link `position`. Whether the storefront honours it depends on the stock
 * GraphQL link resolvers, which do not guarantee position ordering in every
 * 2.4.x patch - verify against the live backend before relying on the ranking
 * being visible.
 */
class Ranker
{
    public const SORT_BESTSELLERS = 'bestsellers';
    public const SORT_NEWEST = 'newest';
    public const SORT_PRICE_ASC = 'price_asc';
    public const SORT_PRICE_DESC = 'price_desc';
    public const SORT_RANDOM = 'random';
    /** Keeps whatever order the strategy produced (co-purchase strength). */
    public const SORT_STRATEGY = 'strategy';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Whether this ranking needs the price map loaded.
     *
     * @param string $sort
     * @return bool
     */
    public function needsPrices(string $sort): bool
    {
        return $sort === self::SORT_PRICE_ASC || $sort === self::SORT_PRICE_DESC;
    }

    /**
     * @param string $sort
     * @param int[] $candidateIds
     * @param int $storeId
     * @param array<int, float> $prices already loaded by the index builder
     * @return int[] best first
     */
    public function rank(string $sort, array $candidateIds, int $storeId, array $prices = []): array
    {
        if (!$candidateIds) {
            return [];
        }

        return match ($sort) {
            self::SORT_NEWEST => $this->byCreatedAt($candidateIds),
            self::SORT_PRICE_ASC => $this->byPrice($candidateIds, $prices, true),
            self::SORT_PRICE_DESC => $this->byPrice($candidateIds, $prices, false),
            self::SORT_RANDOM => $this->shuffled($candidateIds),
            self::SORT_STRATEGY => $candidateIds,
            default => $this->byBestSellers($candidateIds, $storeId),
        };
    }

    /**
     * Quantity sold over the aggregated report table, best first, with products
     * that never sold keeping their existing relative order at the end.
     *
     * Reads sales_bestsellers_aggregated_monthly - the same table
     * BestSellerProvider uses - and degrades to the input order when the Reports
     * aggregation has never run, which is the normal state of a fresh store.
     *
     * @param int[] $candidateIds
     * @param int $storeId
     * @return int[]
     */
    private function byBestSellers(array $candidateIds, int $storeId): array
    {
        $table = $this->resource->getTableName('sales_bestsellers_aggregated_monthly');

        try {
            $connection = $this->resource->getConnection();
            if (!$connection->isTableExists($table)) {
                return $candidateIds;
            }

            $quantities = $connection->fetchPairs(
                $connection->select()
                    ->from($table, ['product_id', 'qty' => new \Zend_Db_Expr('SUM(qty_ordered)')])
                    ->where('store_id = ?', $storeId)
                    ->where('product_id IN (?)', $candidateIds)
                    ->group('product_id')
            );
        } catch (\Exception $e) {
            return $candidateIds;
        }

        return $this->stableSortDesc($candidateIds, static function (int $id) use ($quantities): float {
            return (float) ($quantities[$id] ?? 0);
        });
    }

    /**
     * @param int[] $candidateIds
     * @return int[]
     */
    private function byCreatedAt(array $candidateIds): array
    {
        try {
            $connection = $this->resource->getConnection();
            $created = $connection->fetchPairs(
                $connection->select()
                    ->from(
                        $this->resource->getTableName('catalog_product_entity'),
                        ['entity_id', 'created_at']
                    )
                    ->where('entity_id IN (?)', $candidateIds)
            );
        } catch (\Exception $e) {
            return $candidateIds;
        }

        return $this->stableSortDesc($candidateIds, static function (int $id) use ($created): float {
            $value = $created[$id] ?? null;

            return $value === null ? 0.0 : (float) strtotime((string) $value);
        });
    }

    /**
     * @param int[] $candidateIds
     * @param array<int, float> $prices
     * @param bool $ascending
     * @return int[]
     */
    private function byPrice(array $candidateIds, array $prices, bool $ascending): array
    {
        if (!$prices) {
            return $candidateIds;
        }

        $sign = $ascending ? -1 : 1;

        // Products with no indexed price sort last either way: PHP_INT_MAX would
        // put them first under ascending order, which reads as a bug.
        return $this->stableSortDesc($candidateIds, static function (int $id) use ($prices, $sign): float {
            if (!isset($prices[$id])) {
                return -INF;
            }

            return $sign * $prices[$id];
        });
    }

    /**
     * @param int[] $candidateIds
     * @return int[]
     */
    private function shuffled(array $candidateIds): array
    {
        shuffle($candidateIds);

        return $candidateIds;
    }

    /**
     * Sort descending by a score, keeping the original order among equals.
     *
     * Stability matters: with an unstable sort two products that never sold
     * would swap places between runs, producing a delete-and-reinsert every
     * night and a cache purge with nothing behind it.
     *
     * @param int[] $ids
     * @param callable $score
     * @return int[]
     */
    private function stableSortDesc(array $ids, callable $score): array
    {
        $decorated = [];
        foreach ($ids as $position => $id) {
            $decorated[] = [$score($id), -$position, $id];
        }

        usort($decorated, static function (array $a, array $b): int {
            return [$b[0], $b[1]] <=> [$a[0], $a[1]];
        });

        return array_map(static fn (array $row): int => $row[2], $decorated);
    }
}
