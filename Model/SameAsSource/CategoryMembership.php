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
 *
 * Two views of the same rows come back:
 *
 *  - `all`: every category a product is assigned to. This is the candidate side:
 *    a product sitting in "Men > Shirts" is a valid "same category" candidate for
 *    any source whose category is Shirts.
 *  - `specific`: only the product's most specific categories - an assigned
 *    category is dropped when the product is also assigned to one of its
 *    descendants. This is the source side. A shirt assigned to both "Men" and
 *    "Men > Shirts" means "same category as Shirts", not "anything under Men":
 *    matching on the parent too would let socks, coats and belts compete for the
 *    shirt's related slots, and on a "cheapest first" rule the socks would win.
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
     * @return array{all: array<int, int[]>, specific: array<int, int[]>} productId => categoryId[]
     */
    public function load(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return ['all' => [], 'specific' => []];
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
                        ['path' => 'cce.path']
                    )
                    ->where('ccp.product_id IN (?)', $productIds)
                    ->where('cce.level >= ?', self::MIN_LEVEL)
            );
        } catch (\Exception $e) {
            return ['all' => [], 'specific' => []];
        }

        $paths = [];
        foreach ($rows as $row) {
            $paths[(int) $row['product_id']][(int) $row['category_id']] = (string) $row['path'];
        }

        $all = [];
        $specific = [];
        foreach ($paths as $productId => $categoryPaths) {
            $all[$productId] = array_keys($categoryPaths);
            $specific[$productId] = $this->mostSpecific($categoryPaths);
        }

        return ['all' => $all, 'specific' => $specific];
    }

    /**
     * Invert a membership map into categoryId => productId[], which is the shape
     * the candidate index needs: given the source's categories, the union of
     * those buckets is its candidate set, in one hash lookup per category.
     *
     * Bucket order follows the order of $membership, so feeding it a map whose
     * keys are already in rank order yields rank-ordered buckets.
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

    /**
     * Drop every category that is an ancestor of another category in the set.
     *
     * @param array<int, string> $categoryPaths categoryId => path ("1/2/10/15")
     * @return int[]
     */
    private function mostSpecific(array $categoryPaths): array
    {
        if (count($categoryPaths) < 2) {
            return array_keys($categoryPaths);
        }

        $result = [];
        foreach ($categoryPaths as $categoryId => $path) {
            $prefix = $path . '/';
            foreach ($categoryPaths as $otherId => $otherPath) {
                if ($otherId !== $categoryId && str_starts_with($otherPath, $prefix)) {
                    continue 2;
                }
            }
            $result[] = $categoryId;
        }

        return $result;
    }
}
