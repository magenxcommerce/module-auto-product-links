<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Raises the seeded default rules from 8 to 12 links per product.
 *
 * The storefront's PDP rails became carousels showing four cards at a time out
 * of twelve, so eight links left the last screen of every rail empty. New
 * installs get 12 straight from InstallDefaultRules; this brings existing
 * installs in line.
 *
 * Only the default rules InstallDefaultRules seeded, matched by their exact
 * names, and only while they are still at the old seeded value. A default rule
 * a merchant renamed or whose limit they changed is theirs and stays as is, as
 * does every rule they built themselves. The next rules cron run writes the
 * extra links; nothing is relinked here.
 */
class RaiseDefaultRuleLinkCap implements DataPatchInterface
{
    private const OLD_MAX_LINKS = 8;
    private const NEW_MAX_LINKS = 12;

    /** Names exactly as InstallDefaultRules seeds them. */
    private const DEFAULT_RULE_NAMES = [
        'Default: Related - cheapest in the same category',
        'Default: Up-Sells - most expensive in the same category',
        'Default: Cross-Sells - random cheap add-ons',
        'Default: Frequently Bought Together - from orders',
    ];

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
            ['max_links' => self::NEW_MAX_LINKS],
            [
                'name IN (?)' => self::DEFAULT_RULE_NAMES,
                'max_links = ?' => self::OLD_MAX_LINKS,
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
