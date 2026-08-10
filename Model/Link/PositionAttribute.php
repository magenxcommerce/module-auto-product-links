<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Link;

use Magento\Framework\App\ResourceConnection;

/**
 * Resolves the `position` link attribute id for a link type.
 *
 * Position is not a column on catalog_product_link - it is a link ATTRIBUTE.
 * catalog_product_link_attribute holds one row per (link_type_id, 'position'),
 * and the value lives in catalog_product_link_attribute_int keyed by that row's
 * id together with the link id. Resolved once per run per type and memoized,
 * because it never changes.
 */
class PositionAttribute
{
    private const CODE = 'position';

    /** @var array<int, int|null> */
    private array $cache = [];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param int $linkTypeId
     * @return int|null null when the installation has no position attribute for
     *         this link type, in which case positions are simply not written
     */
    public function getAttributeId(int $linkTypeId): ?int
    {
        if (array_key_exists($linkTypeId, $this->cache)) {
            return $this->cache[$linkTypeId];
        }

        try {
            $connection = $this->resource->getConnection();
            $id = $connection->fetchOne(
                $connection->select()
                    ->from(
                        $this->resource->getTableName('catalog_product_link_attribute'),
                        ['product_link_attribute_id']
                    )
                    ->where('link_type_id = ?', $linkTypeId)
                    ->where('product_link_attribute_code = ?', self::CODE)
            );
        } catch (\Exception $e) {
            $id = false;
        }

        return $this->cache[$linkTypeId] = $id === false || $id === null ? null : (int) $id;
    }
}
