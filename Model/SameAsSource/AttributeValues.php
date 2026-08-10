<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\SameAsSource;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

/**
 * Bulk-loads product attribute values for a set of ids.
 *
 * One query per attribute for the WHOLE set - never one per product. The naive
 * alternatives are both traps worth naming:
 *
 *  - a correlated per-source lookup ("... WHERE tv.value = (SELECT value FROM
 *    catalog_product_entity_int WHERE entity_id = :source ...)") is one round
 *    trip per source product, i.e. 100k queries on a 100k-product rule;
 *  - a self-join on the value table
 *    ("catalog_product_entity_int a JOIN catalog_product_entity_int b
 *      ON a.value = b.value AND a.attribute_id = b.attribute_id")
 *    materialises the cross product of every group: a colour attribute with 20
 *    values over 100k products is ~500M intermediate rows. This is the
 *    O(products squared) trap the bucketing in AttributeValues + CandidateIndex
 *    exists to avoid.
 */
class AttributeValues
{
    private const ENTITY_TYPE = \Magento\Catalog\Model\Product::ENTITY;

    /**
     * @param ResourceConnection $resource
     * @param EavConfig $eavConfig
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * Values of one attribute for the given products, store overrides applied.
     *
     * @param string $attributeCode
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int, string> productId => value
     */
    public function load(string $attributeCode, array $productIds, int $storeId): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds || $attributeCode === '') {
            return [];
        }

        try {
            $attribute = $this->eavConfig->getAttribute(self::ENTITY_TYPE, $attributeCode);
        } catch (\Exception $e) {
            return [];
        }

        if (!$attribute || !$attribute->getId()) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $backendType = (string) $attribute->getBackendType();

        // A static attribute is a column on the entity table, not an EAV row.
        if ($backendType === 'static') {
            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from(
                        $this->resource->getTableName('catalog_product_entity'),
                        ['entity_id', $attributeCode]
                    )
                    ->where('entity_id IN (?)', $productIds)
            );

            return $this->stringify($rows);
        }

        $valueTable = $this->resource->getTableName('catalog_product_entity_' . $backendType);
        if (!$connection->isTableExists($valueTable)) {
            return [];
        }

        // ORDER BY store_id ASC so the store row is read after the default row
        // and overwrites it in the map below. Doing the collapse in PHP rather
        // than with a self-join or a window function keeps this to one plain
        // index range scan.
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($valueTable, ['entity_id', 'store_id', 'value'])
                ->where('attribute_id = ?', (int) $attribute->getId())
                ->where('store_id IN (?)', [0, $storeId])
                ->where('entity_id IN (?)', $productIds)
                ->order('store_id ASC')
        );

        $values = [];
        foreach ($rows as $row) {
            $value = $row['value'];
            if ($value === null || $value === '') {
                // An empty store override means "inherit", not "blank".
                continue;
            }
            $values[(int) $row['entity_id']] = (string) $value;
        }

        return $values;
    }

    /**
     * @param array $rows
     * @return array<int, string>
     */
    private function stringify(array $rows): array
    {
        $values = [];
        foreach ($rows as $productId => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $values[(int) $productId] = (string) $value;
        }

        return $values;
    }
}
