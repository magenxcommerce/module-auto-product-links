<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Setup\Patch\Data;

use Magenx\AutoProductLinks\Model\Ranker;
use Magenx\AutoProductLinks\Model\Rule;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seeds one ready-to-run default rule per link type, so the first cron run
 * relinks the whole catalog without anyone building a rule first:
 *
 *   Related                    cheapest products from the same category
 *   Up-Sells                   most expensive products from the same category
 *   Cross-Sells (cart)         a stable random pick among the cheapest products
 *   Frequently Bought Together products bought in the same orders
 *
 * They are ordinary rules, editable and switchable in the rule grid. They carry
 * priority 1000 so that any rule a merchant adds (priority 0 by default) runs
 * first and takes the products it matches; the defaults then fill whatever no
 * other rule produced links for.
 *
 * Written as plain rows: an absent condition tree is "match everything", which
 * is exactly what these rules want on both sides.
 */
class InstallDefaultRules implements DataPatchInterface
{
    private const DEFAULT_PRIORITY = 1000;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param Json $json
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly Json $json
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $rows = [
            [
                'name' => 'Default: Related - cheapest in the same category',
                'description' => 'Fills "You May Also Like" with the cheapest products sharing the '
                    . 'product\'s most specific category.',
                'link_type' => Rule::LINK_TYPE_RELATED,
                'target_strategy' => Rule::STRATEGY_ATTRIBUTE_MATCH,
                'match_attributes' => $this->json->serialize([Rule::MATCH_CATEGORY]),
                'result_sort' => Ranker::SORT_PRICE_ASC,
                'pick_from_top' => null,
            ],
            [
                'name' => 'Default: Up-Sells - most expensive in the same category',
                'description' => 'Fills "We Also Recommend" with the most expensive products sharing the '
                    . 'product\'s most specific category.',
                'link_type' => Rule::LINK_TYPE_UPSELL,
                'target_strategy' => Rule::STRATEGY_ATTRIBUTE_MATCH,
                'match_attributes' => $this->json->serialize([Rule::MATCH_CATEGORY]),
                'result_sort' => Ranker::SORT_PRICE_DESC,
                'pick_from_top' => null,
            ],
            [
                'name' => 'Default: Cross-Sells - random cheap add-ons',
                'description' => 'Fills the cart cross-sells with a random pick from the 50 cheapest '
                    . 'products in the catalog. The pick is stable per product, so it does not churn nightly.',
                'link_type' => Rule::LINK_TYPE_CROSSSELL,
                'target_strategy' => Rule::STRATEGY_RANDOM,
                'match_attributes' => null,
                'result_sort' => Ranker::SORT_PRICE_ASC,
                'pick_from_top' => 50,
            ],
            [
                'name' => 'Default: Frequently Bought Together - from orders',
                'description' => 'Fills "Frequently Bought Together" with the products most often bought '
                    . 'in the same order. Stays empty until there is enough order history.',
                'link_type' => Rule::LINK_TYPE_BOUGHT_TOGETHER,
                'target_strategy' => Rule::STRATEGY_CO_PURCHASE,
                'match_attributes' => null,
                'result_sort' => Ranker::SORT_STRATEGY,
                'pick_from_top' => null,
            ],
        ];

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('magenx_auto_link_rule');

        foreach ($rows as $row) {
            $connection->insert($table, $row + [
                'is_active' => 1,
                'store_id' => 0,
                'sort_order' => self::DEFAULT_PRIORITY,
                'max_links' => 8,
            ]);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [AddBoughtTogetherLinkType::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
