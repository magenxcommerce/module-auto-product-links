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
 *
 * Which month is "current" is decided in the store timezone (a merchant's idea
 * of the month), but the period BOUNDARIES handed to SQL are plain UTC days,
 * matching sales_order.created_at. Orders placed within the timezone offset of
 * a month boundary therefore land in the adjacent bucket. That is accepted: the
 * read path sums six months of a heuristic, so a few hours of drift at two
 * edges changes nothing. Do not "fix" it by passing the period back through
 * TimezoneInterface::date() - see the comment in rebuildPeriod() for what that
 * costs.
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

        // Plain date arithmetic, deliberately NOT $this->timezone->date(): that
        // method calls setTimezone() on the \DateTime it is handed, and the
        // bootstrap pins date_default_timezone_set('UTC'), so '2026-08-01'
        // becomes 2026-07-31 17:00 in any negative-offset store timezone.
        // '+1 month' then lands on 2026-08-31 and format('Y-m-01') floors it
        // straight back to the period we started from - an empty window, on
        // every store in the Americas, silently wiping the month that
        // clearPeriod() just dropped. The period is already a bare Y-m-01 day;
        // it needs no timezone conversion, only a month added.
        $end = (new \DateTimeImmutable($period))
            ->modify('first day of next month')
            ->format('Y-m-d 00:00:00');

        $range = $this->coPurchase->getOrderRange($start, $end);
        if ($range['count'] === 0) {
            $this->coPurchase->clearPeriod($period);

            return 0;
        }

        $maxOrders = $this->config->getMaxOrdersPerRun();

        // The cap is expressed in orders but the run is sliced by entity_id, so
        // it is applied as an id ceiling. entity_id has auto-increment gaps, so
        // the ceiling covers AT MOST $maxOrders orders and usually slightly
        // fewer - it is a safety bound, not an exact quota. The previous version
        // tracked a $processed counter incremented by the slice WIDTH, which
        // measured the id range walked rather than orders mined and stopped
        // short of the range whenever the ids were sparse.
        $idCeiling = min($range['max'], $range['min'] - 1 + $maxOrders);

        if ($range['count'] > $maxOrders) {
            // Say so rather than silently emitting a partial month: a truncated
            // aggregate looks exactly like a quiet month to whoever reads it.
            $this->logger->warning(sprintf(
                'Magenx_AutoProductLinks: period %s holds %d orders, above the %d per-run limit. '
                . 'Mining stopped at order id %d, so the pairs for this month are incomplete; '
                . 'raise the limit to cover the whole period.',
                $period,
                $range['count'],
                $maxOrders,
                $idCeiling
            ));
        }

        $this->coPurchase->clearPeriod($period);

        $maxItems = $this->config->getMaxItemsPerOrder();
        $written = 0;
        $low = $range['min'] - 1;

        while ($low < $idCeiling) {
            $high = min($low + self::ORDER_SLICE, $idCeiling);
            $written += $this->coPurchase->accumulateSlice($period, $start, $end, $low, $high, $maxItems);
            $low = $high;
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
