<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Link;

use Magenx\AutoProductLinks\Model\Rule;
use Magento\Catalog\Model\Product\Link;
use Magento\Framework\App\ResourceConnection;

/**
 * Maps a rule's link_type value to Magento's numeric link_type_id.
 *
 * The three stock types have fixed ids. Frequently Bought Together is a link
 * type this module registers itself (Setup/Patch/Data/AddBoughtTogetherLinkType),
 * so its id is whatever catalog_product_link_type's auto-increment handed out
 * on that install and is looked up by code, once per process.
 */
class LinkTypeResolver
{
    /** catalog_product_link_type.code of the Frequently Bought Together link type. */
    public const BOUGHT_TOGETHER_CODE = 'magenx_bought_together';

    private const STOCK_TYPE_IDS = [
        Rule::LINK_TYPE_RELATED => Link::LINK_TYPE_RELATED,
        Rule::LINK_TYPE_UPSELL => Link::LINK_TYPE_UPSELL,
        Rule::LINK_TYPE_CROSSSELL => Link::LINK_TYPE_CROSSSELL,
    ];

    /** @var int|false|null null = not looked up yet, false = not installed */
    private int|false|null $boughtTogetherId = null;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param string $linkType one of the Rule::LINK_TYPE_* values
     * @return int|null null for an unknown value, or when the module's own link
     *         type has not been installed (setup:upgrade not run)
     */
    public function getLinkTypeId(string $linkType): ?int
    {
        if (isset(self::STOCK_TYPE_IDS[$linkType])) {
            return self::STOCK_TYPE_IDS[$linkType];
        }

        if ($linkType === Rule::LINK_TYPE_BOUGHT_TOGETHER) {
            return $this->getBoughtTogetherId();
        }

        return null;
    }

    /**
     * @return int|null
     */
    public function getBoughtTogetherId(): ?int
    {
        if ($this->boughtTogetherId === null) {
            $connection = $this->resource->getConnection();
            $id = $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName('catalog_product_link_type'), ['link_type_id'])
                    ->where('code = ?', self::BOUGHT_TOGETHER_CODE)
            );
            $this->boughtTogetherId = $id === false || $id === null ? false : (int) $id;
        }

        return $this->boughtTogetherId === false ? null : $this->boughtTogetherId;
    }
}
