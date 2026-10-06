<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Setup\Patch\Data;

use Magenx\AutoProductLinks\Model\Ranker;
use Magenx\AutoProductLinks\Model\Rule;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Moves the seeded cross-sell rule from "Similar products" to "Random products".
 *
 * It always was a random pick - "Similar products" with nothing to match on and
 * "Randomly pick from the best 50" - but the form said "Similar products", which
 * is not what cart cross-sells are. With pick_from_top set, Random draws with
 * the same hash over the same best 50, so the links it writes are identical and
 * the switch rewrites nothing.
 *
 * Only a rule still exactly as seeded is touched: one a merchant has edited is
 * theirs.
 */
class MoveDefaultCrossSellsToRandom implements DataPatchInterface
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
        $connection->update(
            $this->moduleDataSetup->getTable('magenx_auto_link_rule'),
            ['target_strategy' => Rule::STRATEGY_RANDOM],
            [
                'name = ?' => 'Default: Cross-Sells - random cheap add-ons',
                'link_type = ?' => Rule::LINK_TYPE_CROSSSELL,
                'target_strategy = ?' => Rule::STRATEGY_ATTRIBUTE_MATCH,
                'match_attributes IS NULL',
                'result_sort = ?' => Ranker::SORT_PRICE_ASC,
                'pick_from_top = ?' => 50,
            ]
        );

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [InstallDefaultRules::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
