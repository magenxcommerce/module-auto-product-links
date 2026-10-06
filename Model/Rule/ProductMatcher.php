<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Rule;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\DB\Select;
use Magento\Rule\Model\Condition\Combine;
use Magento\Rule\Model\Condition\Sql\Builder as SqlBuilder;

/**
 * Resolves a condition tree to a set of product ids.
 *
 * The tree is pushed down into ONE SQL query rather than validated per product
 * in PHP. That distinction is the whole performance story of this module: the
 * CatalogRule flavour of the same widget resolves by instantiating and
 * validating every product in the catalog (which is why catalog-rule indexing is
 * the slowest thing in Magento), while the CatalogWidget flavour implements
 * getMappedSqlField() so Magento\Rule\Model\Condition\Sql\Builder can turn the
 * tree into a WHERE fragment. On a 100k-product catalog that is milliseconds
 * instead of minutes, per tree, per rule, per night.
 *
 * Only ids are ever fetched. Loading product models here is the memory cliff.
 */
class ProductMatcher
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param SqlBuilder $sqlBuilder
     * @param Visibility $visibility
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly SqlBuilder $sqlBuilder,
        private readonly Visibility $visibility
    ) {
    }

    /**
     * Product ids matching the tree.
     *
     * @param Combine $tree
     * @param int $storeId
     * @param int|null $limit null for every match
     * @return int[] ascending by entity_id
     */
    public function match(Combine $tree, int $storeId, ?int $limit = null): array
    {
        if ($limit !== null && $limit < 1) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);

        // Never link to (or from) something a shopper cannot open. A link to an
        // invisible configurable variant is a row the storefront silently drops,
        // leaving a short rail with no explanation. Applied to the SOURCE side
        // too, not just the target - a rule matching invisible variants would
        // otherwise write links nobody can ever see.
        // An instance call: getVisibleInCatalogIds() is not static, and calling it
        // statically is a fatal Error on PHP 8 - which failed every rule.
        $collection->setVisibility($this->visibility->getVisibleInCatalogIds());

        // Joins in exactly the attributes the tree references - no more.
        $tree->collectValidatedAttributes($collection);
        $this->sqlBuilder->attachConditionToCollection($collection, $tree);

        $select = $collection->getSelect();
        $select->reset(Select::COLUMNS)->columns(['entity_id' => 'e.entity_id']);

        // A condition on a multi-valued attribute can join a product more than
        // once; ids only, so DISTINCT is cheap.
        $select->distinct(true);

        // Ordered so a run is reproducible: the batches, and therefore the log
        // and the order links are written in, are the same every night.
        $select->order('e.entity_id ASC');
        if ($limit !== null) {
            $select->limit($limit);
        }

        return array_map('intval', $collection->getConnection()->fetchCol($select));
    }

    /**
     * Whether a tree resolves to more than a cap - used to refuse a rule whose
     * target conditions are too broad to hold in memory, rather than letting the
     * cron exhaust itself.
     *
     * Asks for cap + 1 rows and checks the overflow, which is far cheaper than a
     * COUNT over the same conditions.
     *
     * @param Combine $tree
     * @param int $storeId
     * @param int $cap
     * @return array{ids: int[], exceeded: bool}
     */
    public function matchCapped(Combine $tree, int $storeId, int $cap): array
    {
        $ids = $this->match($tree, $storeId, $cap + 1);

        if (count($ids) > $cap) {
            return ['ids' => array_slice($ids, 0, $cap), 'exceeded' => true];
        }

        return ['ids' => $ids, 'exceeded' => false];
    }
}
