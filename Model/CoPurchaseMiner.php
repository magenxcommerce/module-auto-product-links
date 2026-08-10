<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model;

use Magenx\AutoProductLinks\Model\ResourceModel\CoPurchase as CoPurchaseResource;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds the co-purchase aggregate.
 *
 * Only the current month is rebuilt on a normal night, plus the previous month
 * during the first few days of a new one so late or imported orders are picked
 * up. Older months are immutable and left alone - which is what keeps a nightly
 * run proportional to a month of orders rather than to the whole look-back
 * window.
 *
 * Each period is cleared and rebuilt rather than accumulated. That is what makes
 * the job safe to re-run: a crash half way through leaves a partial month the
 * next run simply rebuilds. The obvious alternative - a "last processed order
 * id" watermark - is rejected because sales_order.entity_id has auto-increment
 * gaps under concurrency, so the safety-lag re-scan every such scheme needs
 * would double-count against the accumulating upsert.
 */
class CoPurchaseMiner
{
    /** Orders per slice inside a period. */
    private const ORDER_SLICE = 5000;

    /** Days into a new month during which the previous one is still rebuilt. */
    private const PREVIOUS_MONTH_GRACE_DAYS = 3;

    /**
     * @param CoPurchaseResource $coPurchase
     * @param Config $config
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CoPurchaseResource $coPurchase,
        private readonly Config $config,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function mine(): void
    {
        if (!$this->coPurchase->isMinable()) {
            $this->logger->warning(
                'Magenx_AutoProductLinks: sales tables are not reachable on this connection '
                . '(split database?), co-purchase mining skipped.'
            );

            return;
        }

        $rebuilt = 0;
        foreach ($this->periodsToRebuild() as $period) {
            $rebuilt += $this->rebuildPeriod($period);
        }

        $pruned = $this->coPurchase->prune($this->cutoffPeriod());

        $this->logger->info(sprintf(
            'Magenx_AutoProductLinks: co-purchase mining done, %d pair rows written, %d aged rows pruned.',
            $rebuilt,
            $pruned
        ));
    }

    /**
     * @param string $period Y-m-01
     * @return int rows written
     */
    private function rebuildPeriod(string $period): int
    {
        $start = $period . ' 00:00:00';
        $end = $this->timezone->date(new \DateTime($period))
            ->modify('+1 month')
            ->format('Y-m-01 00:00:00');

        $range = $this->coPurchase->getOrderRange($start, $end);
        if ($range['count'] === 0) {
            $this->coPurchase->clearPeriod($period);

            return 0;
        }

        $maxOrders = $this->config->getMaxOrdersPerRun();
        if ($range['count'] > $maxOrders) {
            // Say so rather than silently emitting a partial month: a truncated
            // aggregate looks exactly like a quiet month to whoever reads it.
            $this->logger->warning(sprintf(
                'Magenx_AutoProductLinks: period %s holds %d orders, above the %d per-run limit. '
                . 'Only the first %d orders were counted; raise the limit or the pairs for this month '
                . 'will stay incomplete.',
                $period,
                $range['count'],
                $maxOrders,
                $maxOrders
            ));
        }

        $this->coPurchase->clearPeriod($period);

        $maxItems = $this->config->getMaxItemsPerOrder();
        $written = 0;
        $processed = 0;
        $low = $range['min'] - 1;

        while ($low < $range['max'] && $processed < $maxOrders) {
            $high = $low + self::ORDER_SLICE;
            $written += $this->coPurchase->accumulateSlice($period, $start, $end, $low, $high, $maxItems);
            $low = $high;
            $processed += self::ORDER_SLICE;
        }

        return $written;
    }

    /**
     * The periods a run should rebuild: this month, and briefly last month too.
     *
     * @return string[]
     */
    private function periodsToRebuild(): array
    {
        $today = $this->timezone->date();
        $periods = [$today->format('Y-m-01')];

        if ((int) $today->format('j') <= self::PREVIOUS_MONTH_GRACE_DAYS) {
            $periods[] = $this->timezone->date()
                ->modify('first day of last month')
                ->format('Y-m-01');
        }

        return $periods;
    }

    /**
     * First day of the oldest month still inside the look-back window.
     *
     * @return string Y-m-01
     */
    private function cutoffPeriod(): string
    {
        $months = max(1, $this->config->getLookbackMonths());

        return $this->timezone->date()
            ->modify('first day of this month')
            ->modify('-' . ($months - 1) . ' months')
            ->format('Y-m-01');
    }
}
