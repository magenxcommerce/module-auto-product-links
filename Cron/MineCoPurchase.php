<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Cron;

use Magenx\AutoProductLinks\Model\Config;
use Magenx\AutoProductLinks\Model\CoPurchaseMiner;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds the co-purchase aggregate from order history.
 *
 * Must run BEFORE the rule job, or a rule using the bought-together strategy
 * works from yesterday's numbers.
 */
class MineCoPurchase
{
    /**
     * @param CoPurchaseMiner $miner
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CoPurchaseMiner $miner,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Never throws: a failed mining run must not take the cron group down with
     * it, and the rules can still run from the previous aggregate.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $this->miner->mine();
        } catch (\Throwable $e) {
            $this->logger->error('Magenx_AutoProductLinks: co-purchase mining failed. ' . $e->getMessage());
        }
    }
}
