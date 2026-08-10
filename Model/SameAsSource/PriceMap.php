<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\SameAsSource;

use Magento\Framework\App\ResourceConnection;

/**
 * Prices for a set of products, read from the native price index in one query.
 *
 * Uses IF(final_price > 0, final_price, min_price), the same correction
 * PriceHistoryGraphQl's snapshot cron documents: a SIMPLE product's authoritative
 * single-unit price is final_price, but a COMPOSITE parent (configurable, bundle,
 * grouped) carries final_price = 0 in the index and the figure the storefront
 * shows is min_price - the cheapest child's final price. Reading final_price
 * alone would price every configurable at 0 and put them in the wrong band.
 */
class PriceMap
{
    private const CUSTOMER_GROUP_ID = 0;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param int[] $productIds
     * @param int $websiteId
     * @return array<int, float> productId => price
     */
    public function load(array $productIds, int $websiteId): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }

        try {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from(
                        $this->resource->getTableName('catalog_product_index_price'),
                        [
                            'entity_id',
                            'price' => new \Zend_Db_Expr('MIN(IF(final_price > 0, final_price, min_price))'),
                        ]
                    )
                    ->where('website_id = ?', $websiteId)
                    ->where('customer_group_id = ?', self::CUSTOMER_GROUP_ID)
                    ->where('entity_id IN (?)', $productIds)
                    ->group('entity_id')
            );
        } catch (\Exception $e) {
            // The price index has never been built. No prices means no price
            // band can be applied; the caller degrades to an unbanded match
            // rather than linking nothing.
            return [];
        }

        $prices = [];
        foreach ($rows as $productId => $price) {
            $price = (float) $price;
            if ($price > 0) {
                $prices[(int) $productId] = $price;
            }
        }

        return $prices;
    }
}
