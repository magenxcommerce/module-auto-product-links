<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\SameAsSource;

use Magento\Framework\App\ResourceConnection;

/**
 * Category membership for a set of products, loaded in one query.
 *
 * Only navigable categories (level >= 2) count: levels 0 and 1 are the tree root
 * and the store root, which every product belongs to, so including them would
 * make "shares a category with the source" true for the entire catalog. Same
 * level filter, for the same reason, as BestSellerProvider::getBadge().
 */
class CategoryMembership
{
    private const MIN_LEVEL = 2;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param int[] $productIds
     * @return array<int, int[]> productId => categoryId[]
     */
    public function load(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }

        try {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(
                        ['ccp' => $this->resource->getTableName('catalog_category_product')],
                        ['product_id' => 'ccp.product_id', 'category_id' => 'ccp.category_id']
                    )
                    ->join(
                        ['cce' => $this->resource->getTableName('catalog_category_entity')],
                        'cce.entity_id = ccp.category_id',
                        []
                    )
                    ->where('ccp.product_id IN (?)', $productIds)
                    ->where('cce.level >= ?', self::MIN_LEVEL)
            );
        } catch (\Exception $e) {
            return [];
        }

        $membership = [];
        foreach ($rows as $row) {
            $membership[(int) $row['product_id']][] = (int) $row['category_id'];
        }

        return $membership;
    }

    /**
     * Invert a membership map into categoryId => productId[], which is the shape
     * the candidate index needs: given the source's categories, the union of
     * those buckets is its candidate set, in one hash lookup per category.
     *
     * @param array<int, int[]> $membership
     * @return array<int, int[]>
     */
    public function invert(array $membership): array
    {
        $byCategory = [];
        foreach ($membership as $productId => $categoryIds) {
            foreach ($categoryIds as $categoryId) {
                $byCategory[$categoryId][] = (int) $productId;
            }
        }

        return $byCategory;
    }
}
