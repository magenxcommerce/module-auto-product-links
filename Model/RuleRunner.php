<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model;

use Magenx\AutoProductLinks\Model\Link\LinkWriter;
use Magenx\AutoProductLinks\Model\Link\WriteResult;
use Magenx\AutoProductLinks\Model\ResourceModel\Ledger;
use Magenx\AutoProductLinks\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magenx\AutoProductLinks\Model\Rule\ProductMatcher;
use Magenx\AutoProductLinks\Model\Strategy\StrategyPool;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the rules and writes the links.
 *
 * Per rule: resolve the target pool once, resolve a capped slice of the source
 * set, then walk that slice in batches - build the bucketed candidate index for
 * the batch, ask the strategy for targets, hand the result to the link writer,
 * invalidate the touched products. One transaction per batch, never one per
 * rule: catalog_product_link is read on every product page, so a long
 * transaction there blocks the admin.
 *
 * A rule that is inactive or outside its date range is not skipped - it is run
 * in DELETE-ONLY mode, so switching a rule off removes its links on the next
 * run, which is what a merchant expects.
 */
class RuleRunner
{
    /**
     * @param RuleCollectionFactory $ruleCollectionFactory
     * @param ProductMatcher $productMatcher
     * @param CandidateIndexBuilder $indexBuilder
     * @param StrategyPool $strategyPool
     * @param LinkWriter $linkWriter
     * @param Ledger $ledger
     * @param RunCursor $cursor
     * @param CacheInvalidator $cacheInvalidator
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RuleCollectionFactory $ruleCollectionFactory,
        private readonly ProductMatcher $productMatcher,
        private readonly CandidateIndexBuilder $indexBuilder,
        private readonly StrategyPool $strategyPool,
        private readonly LinkWriter $linkWriter,
        private readonly Ledger $ledger,
        private readonly RunCursor $cursor,
        private readonly CacheInvalidator $cacheInvalidator,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run every rule, or one rule when an id is given.
     *
     * @param int|null $onlyRuleId
     * @return WriteResult
     */
    public function run(?int $onlyRuleId = null): WriteResult
    {
        $total = new WriteResult();

        $collection = $this->ruleCollectionFactory->create()->addRunOrder();
        if ($onlyRuleId !== null) {
            $collection->addFieldToFilter('rule_id', $onlyRuleId);
        }

        foreach ($collection as $rule) {
            /** @var Rule $rule */
            try {
                $total->merge($this->runRule($rule));
            } catch (\Throwable $e) {
                // One broken rule must not stop the others. The likely causes -
                // a condition referencing a deleted attribute, a strategy whose
                // module was removed - are all per-rule.
                $this->logger->error(sprintf(
                    'Magenx_AutoProductLinks: rule %d ("%s") failed: %s',
                    (int) $rule->getId(),
                    (string) $rule->getData('name'),
                    $e->getMessage()
                ));
            }
        }

        // touchedProductIds carries only products that were really written, so a
        // dry run reaches here with an empty list and broadcasts nothing.
        if ($total->touchedProductIds && $this->config->shouldInvalidateCache()) {
            $this->cacheInvalidator->invalidateProducts($total->touchedProductIds);
        }

        return $total;
    }

    /**
     * @param Rule $rule
     * @return WriteResult
     */
    private function runRule(Rule $rule): WriteResult
    {
        $ruleId = (int) $rule->getId();
        $storeId = (int) $rule->getData('store_id');
        $result = new WriteResult();

        // Inactive or expired: release what it owns and stop. Its links go on
        // the next run, so switching a rule off is reversible and visible.
        if (!$rule->isRunnableNow()) {
            if (!$this->config->isDryRun($storeId)) {
                $touched = $this->ledger->releaseRule($ruleId);
                if ($touched) {
                    // Deliberately not folded into $result->deleted, which counts
                    // links: releaseRule reports the PRODUCTS it touched, and
                    // mixing the two units makes the run summary lie.
                    $result->touchedProductIds = $touched;
                    $this->logger->info(sprintf(
                        'Magenx_AutoProductLinks: rule %d ("%s") is inactive or expired; '
                        . 'released its links on %d products.',
                        $ruleId,
                        (string) $rule->getData('name'),
                        count($touched)
                    ));
                }
                $this->cursor->reset($ruleId);
            }

            return $result;
        }

        $linkTypeId = $this->linkWriter->resolveLinkTypeId((string) $rule->getData('link_type'));
        if ($linkTypeId === null) {
            $this->logger->warning(sprintf(
                'Magenx_AutoProductLinks: rule %d has unknown link type "%s", skipped.',
                $ruleId,
                (string) $rule->getData('link_type')
            ));

            return $result;
        }

        $strategy = $this->strategyPool->get((string) $rule->getData('target_strategy'));
        if ($strategy === null) {
            $this->logger->warning(sprintf(
                'Magenx_AutoProductLinks: rule %d uses unknown strategy "%s", skipped.',
                $ruleId,
                (string) $rule->getData('target_strategy')
            ));

            return $result;
        }

        // --- the candidate pool, resolved once for the whole rule ---------
        $poolCap = $this->config->getTargetPoolCap($storeId);
        $pool = $this->productMatcher->matchCapped($rule->getActions(), $storeId, $poolCap);
        if ($pool['exceeded']) {
            $this->logger->warning(sprintf(
                'Magenx_AutoProductLinks: rule %d ("%s") target conditions match more than %d products; '
                . 'skipped. Narrow the target conditions rather than raising the cap.',
                $ruleId,
                (string) $rule->getData('name'),
                $poolCap
            ));

            return $result;
        }
        if (!$pool['ids']) {
            $this->logger->info(sprintf(
                'Magenx_AutoProductLinks: rule %d ("%s") matched no candidate products.',
                $ruleId,
                (string) $rule->getData('name')
            ));

            return $result;
        }

        // --- the source slice for this run --------------------------------
        $perRun = $this->config->getMaxSourceProductsPerRun($storeId);
        $startCursor = $this->cursor->get($ruleId);
        $sources = $this->productMatcher->match($rule->getConditions(), $storeId, $perRun, $startCursor);

        if (!$sources) {
            // Either the rule matches nothing, or the previous run finished the
            // whole set. Rewinding covers both and costs one query next time.
            $this->cursor->reset($ruleId);

            return $result;
        }

        $websiteId = $this->resolveWebsiteId($storeId);
        $maxLinks = (int) $rule->getData('max_links') ?: $this->config->getDefaultMaxLinks($storeId);
        $positionBase = $this->config->getAutoPositionBase($storeId);
        $dryRun = $this->config->isDryRun($storeId);
        $batchSize = $this->config->getBatchSize($storeId);
        $connection = $this->resource->getConnection();

        // Built ONCE, outside the batch loop. Everything in it depends on the
        // rule and its candidate pool only; folding a batch onto it is the cheap
        // per-batch half. Building it inside the loop instead re-ran the pool's
        // attribute, category and price queries - and the ranking, bestsellers
        // aggregate included - for every batch.
        $poolIndex = $this->indexBuilder->buildPool($rule, $pool['ids'], $storeId, $websiteId);

        foreach (array_chunk($sources, $batchSize) as $batch) {
            $index = $this->indexBuilder->withSources($poolIndex, $batch, $storeId, $websiteId);
            $desired = $strategy->resolve($rule, $batch, $index, $maxLinks);

            if ($dryRun) {
                $result->merge($this->linkWriter->apply(
                    $ruleId,
                    $linkTypeId,
                    $desired,
                    $batch,
                    $positionBase,
                    true
                ));
                continue;
            }

            $connection->beginTransaction();
            try {
                $result->merge($this->linkWriter->apply(
                    $ruleId,
                    $linkTypeId,
                    $desired,
                    $batch,
                    $positionBase,
                    false
                ));
                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        }

        if (!$dryRun) {
            $this->cursor->set($ruleId, (int) end($sources));
        }

        $this->logger->info(sprintf(
            'Magenx_AutoProductLinks: rule %d ("%s") %s sources=%d candidates=%d cursor=%d%s',
            $ruleId,
            (string) $rule->getData('name'),
            $result->summary(),
            count($sources),
            count($pool['ids']),
            $dryRun ? $startCursor : (int) end($sources),
            $dryRun ? ' [DRY RUN - nothing written]' : ''
        ));

        return $result;
    }

    /**
     * @param int $storeId
     * @return int
     */
    private function resolveWebsiteId(int $storeId): int
    {
        try {
            return (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (\Exception $e) {
            return (int) $this->storeManager->getWebsite()->getId();
        }
    }
}
