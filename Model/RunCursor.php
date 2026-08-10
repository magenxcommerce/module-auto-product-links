<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model;

use Magento\Framework\FlagManager;

/**
 * Where each rule's last run stopped.
 *
 * Stored in Magento's own `flag` table rather than a table of our own - one
 * scalar per rule does not justify a schema. A catalog larger than the per-run
 * cap therefore converges over several nights instead of reprocessing the same
 * prefix forever, and the cursor wraps to 0 once a rule has been through its
 * whole source set.
 */
class RunCursor
{
    private const RULE_PREFIX = 'magenx_auto_link_rule_cursor_';

    /**
     * @param FlagManager $flagManager
     */
    public function __construct(
        private readonly FlagManager $flagManager
    ) {
    }

    /**
     * @param int $ruleId
     * @return int last processed product id, 0 when the rule starts from the top
     */
    public function get(int $ruleId): int
    {
        return (int) $this->flagManager->getFlagData(self::RULE_PREFIX . $ruleId);
    }

    /**
     * @param int $ruleId
     * @param int $productId
     * @return void
     */
    public function set(int $ruleId, int $productId): void
    {
        $this->flagManager->saveFlag(self::RULE_PREFIX . $ruleId, $productId);
    }

    /**
     * Start this rule from the top again - after a full pass, or when an admin
     * asks for an immediate run.
     *
     * @param int $ruleId
     * @return void
     */
    public function reset(int $ruleId): void
    {
        $this->flagManager->deleteFlag(self::RULE_PREFIX . $ruleId);
    }
}
