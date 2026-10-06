<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Setup\Patch\Data;

use Magenx\AutoProductLinks\Model\Link\LinkTypeResolver;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Registers the Frequently Bought Together product link type.
 *
 * Magento has no such link type, and cross-sells cannot double for it: they are
 * what the cart shows, and a merchant wants those to be cheap add-ons rather
 * than whatever happened to share a basket. So the module adds a fourth link
 * type to catalog_product_link_type and writes its links into the same
 * catalog_product_link table as the stock three. That keeps the ledger, the
 * position handling and the product-delete cascades identical for all four, and
 * lets the GraphQL field reuse Magento's own linked-products resolver.
 *
 * The `position` link attribute is registered too. It is not optional: Magento's
 * link collection orders by `position`, which only exists as a column once the
 * attribute is joined, so a link type without it fails the GraphQL query.
 *
 * The type is deliberately NOT added to Magento\Catalog\Model\Product\LinkTypeProvider,
 * so the admin product form and the product link repository never see it - a
 * product save in the admin therefore cannot delete these links.
 */
class AddBoughtTogetherLinkType implements DataPatchInterface
{
    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $typeTable = $this->moduleDataSetup->getTable('catalog_product_link_type');
        $attributeTable = $this->moduleDataSetup->getTable('catalog_product_link_attribute');

        $linkTypeId = (int) $connection->fetchOne(
            $connection->select()
                ->from($typeTable, ['link_type_id'])
                ->where('code = ?', LinkTypeResolver::BOUGHT_TOGETHER_CODE)
        );

        if (!$linkTypeId) {
            $connection->insert($typeTable, ['code' => LinkTypeResolver::BOUGHT_TOGETHER_CODE]);
            $linkTypeId = (int) $connection->lastInsertId($typeTable);
        }

        $hasPosition = (bool) $connection->fetchOne(
            $connection->select()
                ->from($attributeTable, ['product_link_attribute_id'])
                ->where('link_type_id = ?', $linkTypeId)
                ->where('product_link_attribute_code = ?', 'position')
        );

        if (!$hasPosition) {
            $connection->insert($attributeTable, [
                'link_type_id' => $linkTypeId,
                'product_link_attribute_code' => 'position',
                'data_type' => 'int',
            ]);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
