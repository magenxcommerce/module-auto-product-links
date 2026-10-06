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
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the rules and writes the links.
 *
 * Rules are run one link type at a time, in priority order, and for each link
 * type every product has at most ONE owning rule:
 *
 *   - a rule takes the source products it matches that no earlier rule has
 *     already produced links for, computes their links and writes them;
 *   - a product the rule finds nothing for stays open for the next rule, which
 *     is how the low-priority default rules fill whatever the merchant's own
 *     rules leave empty;
 *   - once every rule has run, any product that still carries auto links of
 *     that type but was claimed by no rule this time - it left a rule's
 *     conditions, or its rule was switched off - has those links removed.
 *
 * Without the single owner two rules filling the same link type would each
 * replace the other's links on every run, rewriting (and purging the cache of)
 * every product they share, every night.
 *
 * Every matching product is processed on every run, in batches with one
 * transaction each - never one per rule: catalog_product_link is read on every
 * product page, so a long transaction there blocks the storefront. A batch only
 * writes when a product's computed links differ from what it has.
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
        private readonly CacheInvalidator $cacheInvalidator,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run every rule, or only the rules of one link type.
     *
     * There is no "run one rule": which products a rule owns depends on every
     * higher-priority rule of the same link type, so a link type is the
     * smallest unit that can be run on its own.
     *
     * @param string|null $onlyLinkType one of the Rule::LINK_TYPE_* values
     * @return WriteResult
     */
    public function run(?string $onlyLinkType = null): WriteResult
    {
        $total = new WriteResult();

        $byLinkType = [];
        foreach ($this->ruleCollectionFactory->create()->addRunOrder() as $rule) {
            /** @var Rule $rule */
            $byLinkType[(string) $rule->getData('link_type')][] = $rule;
        }

        foreach ($byLinkType as $linkType => $rules) {
            if ($onlyLinkType !== null && $linkType !== $onlyLinkType) {
                continue;
            }
            $total->merge($this->runLinkType((string) $linkType, $rules));
        }

        // touchedProductIds carries only products that were really written, so a
        // dry run reaches here with an empty list and broadcasts nothing.
        if ($total->touchedProductIds && $this->config->shouldInvalidateCache()) {
            $this->cacheInvalidator->invalidateProducts($total->touchedProductIds);
        }

        return $total;
    }

    /**
     * @param string $linkType
     * @param Rule[] $rules in run order
     * @return WriteResult
     */
    private function runLinkType(string $linkType, array $rules): WriteResult
    {
        $result = new WriteResult();

        $linkTypeId = $this->linkWriter->resolveLinkTypeId($linkType);
        if ($linkTypeId === null) {
            $this->logger->warning(sprintf(
                'Magenx_AutoProductLinks: link type "%s" is unknown or not installed (run setup:upgrade); '
                . '%d rule(s) skipped.',
                $linkType,
                count($rules)
            ));

            return $result;
        }

        $dryRun = $this->config->isDryRun();

        /** @var array<int, true> $claimed source product id => claimed by an earlier rule */
        $claimed = [];

        foreach ($rules as $rule) {
            if (!$rule->isRunnableNow()) {
                continue;
            }

            try {
                $result->merge($this->runRule($rule, $linkTypeId, $claimed, $dryRun));
            } catch (\Throwable $e) {
                // One broken rule must not stop the others. Its products are
                // treated as still claimed by it, so the clean-up below leaves
                // its existing links alone instead of wiping them because of
                // what is probably a fixable configuration error.
                foreach ($this->ledger->getProductIdsByRule((int) $rule->getId()) as $productId) {
                    $claimed[$productId] = true;
                }
                $this->logger->error(sprintf(
                    'Magenx_AutoProductLinks: rule %d ("%s") failed, its existing links were kept: %s',
                    (int) $rule->getId(),
                    (string) $rule->getData('name'),
                    $e->getMessage()
                ));
            }
        }

        // --- clean-up: auto links no rule claimed this run --------------------
        $stale = array_values(array_filter(
            $this->ledger->getOwnedProductIds($linkTypeId),
            static fn (int $productId): bool => !isset($claimed[$productId])
        ));

        if ($stale) {
            if ($dryRun) {
                $removed = $this->ledger->countOwned($linkTypeId, $stale);
            } else {
                $removed = $this->ledger->releaseProducts($linkTypeId, $stale);
                foreach ($stale as $productId) {
                    $result->touchedProductIds[] = $productId;
                }
            }
            $result->deleted += $removed;

            $this->logger->info(sprintf(
                'Magenx_AutoProductLinks: %s - removed %d auto links from %d products no rule matches any more.%s',
                $linkType,
                $removed,
                count($stale),
                $dryRun ? ' [DRY RUN - nothing written]' : ''
            ));
        }

        return $result;
    }

    /**
     * @param Rule $rule
     * @param int $linkTypeId
     * @param array<int, true> $claimed updated with the products this rule claims
     * @param bool $dryRun
     * @return WriteResult
     * @throws LocalizedException
     */
    private function runRule(Rule $rule, int $linkTypeId, array &$claimed, bool $dryRun): WriteResult
    {
        $ruleId = (int) $rule->getId();
        $storeId = (int) $rule->getData('store_id');
        $result = new WriteResult();

        $strategy = $this->strategyPool->get((string) $rule->getData('target_strategy'));
        if ($strategy === null) {
            throw new LocalizedException(__(
                'Unknown strategy "%1".',
                (string) $rule->getData('target_strategy')
            ));
        }

        // --- the candidate pool, resolved once for the whole rule ---------
        $poolCap = $this->config->getTargetPoolCap();
        $pool = $this->productMatcher->match($rule->getActions(), $storeId, $poolCap + 1);
        if (count($pool) > $poolCap) {
            throw new LocalizedException(__(
                'The "Link To These Products" conditions match more than %1 products. '
                . 'Narrow them, or raise "Maximum Candidate Products per Rule".',
                $poolCap
            ));
        }

        // --- the source products no earlier rule has claimed --------------
        $matched = $this->productMatcher->match($rule->getConditions(), $storeId);
        $sources = array_values(array_filter(
            $matched,
            static fn (int $productId): bool => !isset($claimed[$productId])
        ));

        $linkedSources = 0;
        if ($pool && $sources) {
            $websiteId = $this->resolveWebsiteId($storeId);
            $maxLinks = (int) $rule->getData('max_links') ?: $this->config->getDefaultMaxLinks();
            $positionBase = $this->config->getAutoPositionBase();
            $connection = $this->resource->getConnection();

            // Built ONCE, outside the batch loop: everything in it depends on the
            // rule and its candidate pool only.
            $poolIndex = $this->indexBuilder->buildPool($rule, $pool, $storeId, $websiteId);

            foreach (array_chunk($sources, $this->config->getBatchSize()) as $batch) {
                $index = $this->indexBuilder->withSources($poolIndex, $batch, $storeId, $websiteId);
                $desired = array_filter($strategy->resolve($rule, $batch, $index, $maxLinks));
                if (!$desired) {
                    continue;
                }

                // Only the products this rule produced links for are handed to
                // the writer - and claimed. The rest are left for later rules.
                $claimedHere = array_map('intval', array_keys($desired));
                foreach ($claimedHere as $productId) {
                    $claimed[$productId] = true;
                }
                $linkedSources += count($claimedHere);

                if (!$dryRun) {
                    $connection->beginTransaction();
                }
                try {
                    $result->merge($this->linkWriter->apply(
                        $ruleId,
                        $linkTypeId,
                        $desired,
                        $claimedHere,
                        $positionBase,
                        $dryRun
                    ));
                    if (!$dryRun) {
                        $connection->commit();
                    }
                } catch (\Throwable $e) {
                    if (!$dryRun) {
                        $connection->rollBack();
                    }
                    throw $e;
                }
            }
        }

        $this->logger->info(sprintf(
            'Magenx_AutoProductLinks: rule %d ("%s") %s matched=%d already_claimed=%d linked=%d candidates=%d%s',
            $ruleId,
            (string) $rule->getData('name'),
            $result->summary(),
            count($matched),
            count($matched) - count($sources),
            $linkedSources,
            count($pool),
            $dryRun ? ' [DRY RUN - nothing written]' : ''
        ));

        return $result;
    }

    /**
     * The website whose price index a rule reads.
     *
     * A rule evaluated in the default scope (store 0) must not use website 0:
     * the price index has no rows for the admin website, so every price-based
     * ranking and price band would silently see no prices at all. The default
     * store view's website is used instead.
     *
     * @param int $storeId
     * @return int
     */
    private function resolveWebsiteId(int $storeId): int
    {
        try {
            if ($storeId > 0) {
                return (int) $this->storeManager->getStore($storeId)->getWebsiteId();
            }
            $default = $this->storeManager->getDefaultStoreView();
            if ($default !== null) {
                return (int) $default->getWebsiteId();
            }
        } catch (\Exception $e) {
            // Fall through to the default website.
        }

        return (int) $this->storeManager->getWebsite()->getId();
    }
}
