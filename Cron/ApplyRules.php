<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Cron;

use Magenx\AutoProductLinks\Model\Config;
use Magenx\AutoProductLinks\Model\RuleRunner;
use Psr\Log\LoggerInterface;

/**
 * Applies the auto-product-link rules.
 *
 * Schedule after the co-purchase mining job and after the catalog reindex, so
 * it works from a current aggregate and current prices.
 */
class ApplyRules
{
    /**
     * @param RuleRunner $runner
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RuleRunner $runner,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Never throws: a failed link run must not take the cron group down with it.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $result = $this->runner->run();
            $this->logger->info(sprintf(
                'Magenx_AutoProductLinks: rule run complete, %s%s',
                $result->summary(),
                $this->config->isDryRun() ? ' [DRY RUN - nothing written]' : ''
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Magenx_AutoProductLinks: rule run failed. ' . $e->getMessage());
        }
    }
}
