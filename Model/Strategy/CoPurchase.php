<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Strategy;

use Magenx\AutoProductLinks\Api\TargetStrategyInterface;
use Magenx\AutoProductLinks\Model\CandidateIndex;
use Magenx\AutoProductLinks\Model\Config;
use Magenx\AutoProductLinks\Model\Ranker;
use Magenx\AutoProductLinks\Model\ResourceModel\CoPurchase as CoPurchaseResource;
use Magenx\AutoProductLinks\Model\Rule;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Links to the products most often bought in the same order as the source.
 *
 * This is what gives the storefront's "Frequently Bought Together" rail real
 * data: that rail renders Magento's native cross-sells, which until now nothing
 * populated.
 *
 * Note this is a target STRATEGY, not a hardcoded cross-sell behaviour. A rule
 * chooses it, and a rule chooses its own link type - so a merchant can just as
 * well drive Related Products from co-purchase history. The mined partners are
 * still intersected with the rule's target conditions and its match attributes,
 * so "only ever suggest accessories, and only ones bought with this" is a single
 * rule rather than a special case in code.
 */
class CoPurchase implements TargetStrategyInterface
{
    /**
     * @param CoPurchaseResource $coPurchase
     * @param Config $config
     * @param TimezoneInterface $timezone
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly CoPurchaseResource $coPurchase,
        private readonly Config $config,
        private readonly TimezoneInterface $timezone,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return Rule::STRATEGY_CO_PURCHASE;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): \Magento\Framework\Phrase
    {
        return __('Bought together (from order history)');
    }

    /**
     * @inheritDoc
     */
    public function resolve(Rule $rule, array $sourceIds, CandidateIndex $index, int $maxLinks): array
    {
        $storeId = (int) $rule->getData('store_id');
        $storeIds = $this->resolveStoreIds($storeId);
        $cutoff = $this->cutoffPeriod($storeId);

        $partners = $this->coPurchase->getPartners(
            $storeIds,
            $sourceIds,
            $cutoff,
            $this->config->getMinSupport($storeId)
        );

        if (!$partners) {
            return [];
        }

        // The pool is the rule's target conditions. Intersecting against it is
        // what stops a co-purchase rule from linking to anything that ever
        // shared a basket.
        $pool = array_flip($index->getAllIds());
        $keepStrategyOrder = (string) $rule->getData('result_sort') === Ranker::SORT_STRATEGY;
        $percent = (int) $rule->getData('price_band_percent');

        $result = [];
        foreach ($sourceIds as $sourceId) {
            $sourceId = (int) $sourceId;
            $candidates = $partners[$sourceId] ?? [];
            if (!$candidates) {
                continue;
            }

            $candidates = array_values(array_filter(
                $candidates,
                static fn (int $id): bool => $id !== $sourceId && isset($pool[$id])
            ));

            // The same-as-source dimensions apply here too, as filters on the
            // mined partners rather than as the selection mechanism.
            foreach ([
                $index->candidatesBySignature($sourceId),
                $index->candidatesByCategory($sourceId),
                $percent > 0 ? $index->candidatesByPriceBand($sourceId, $percent) : null,
            ] as $set) {
                if ($set === null) {
                    continue;
                }
                $candidates = array_values(array_intersect($candidates, $set));
                if (!$candidates) {
                    break;
                }
            }

            if (!$candidates) {
                continue;
            }

            // getPartners() already ordered by support, strongest first. Any
            // other ranking re-sorts; "strategy" keeps that strength order,
            // which is usually what a bought-together rail wants.
            if (!$keepStrategyOrder) {
                $candidates = $index->sortByRank($candidates);
            }

            $result[$sourceId] = array_slice($candidates, 0, $maxLinks);
        }

        return $result;
    }

    /**
     * Which stores' orders count.
     *
     * A rule scoped to the default scope (0) means "all of them" - store 0 is
     * not a real store that orders are placed in, so filtering on it literally
     * would return nothing.
     *
     * @param int $storeId
     * @return int[]
     */
    private function resolveStoreIds(int $storeId): array
    {
        if ($storeId > 0) {
            return [$storeId];
        }

        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            $ids[] = (int) $store->getId();
        }

        return $ids ?: [0];
    }

    /**
     * First day of the oldest month still inside the look-back window.
     *
     * @param int $storeId
     * @return string Y-m-01
     */
    private function cutoffPeriod(int $storeId): string
    {
        $months = max(1, $this->config->getLookbackMonths($storeId));

        return $this->timezone->date()
            ->modify('first day of this month')
            ->modify('-' . ($months - 1) . ' months')
            ->format('Y-m-01');
    }
}
