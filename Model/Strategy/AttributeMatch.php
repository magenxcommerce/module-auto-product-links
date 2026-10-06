<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Strategy;

use Magenx\AutoProductLinks\Api\TargetStrategyInterface;
use Magenx\AutoProductLinks\Model\CandidateIndex;
use Magenx\AutoProductLinks\Model\Rule;

/**
 * Links to products that resemble the source.
 *
 * "Resemble" is whatever the merchant selected in the rule's match-attribute
 * list: share a colour, share a size, share a manufacturer, sit in the same
 * category, fall inside a price band - or any combination, which is why this is
 * ONE strategy rather than three. Splitting "same category" and "price band"
 * into separate strategies would have made them mutually exclusive, when
 * combining them ("same brand AND within 20% AND in the target conditions") is
 * exactly what a merchant wants for an up-sell rule.
 *
 * With no match attributes selected at all, the strategy degrades to "anything
 * in the target pool, ranked" - which is a legitimate rule (a curated
 * accessories pool cross-sold onto everything), not a misconfiguration.
 */
class AttributeMatch implements TargetStrategyInterface
{
    /**
     * @param Picker $picker
     */
    public function __construct(
        private readonly Picker $picker
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return Rule::STRATEGY_ATTRIBUTE_MATCH;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): \Magento\Framework\Phrase
    {
        return __('Similar products (match attributes, category or price)');
    }

    /**
     * @inheritDoc
     */
    public function resolve(Rule $rule, array $sourceIds, CandidateIndex $index, int $maxLinks): array
    {
        $percent = (int) $rule->getData('price_band_percent');
        $needed = $this->picker->needed($rule, $maxLinks);
        $result = [];

        foreach ($sourceIds as $sourceId) {
            $sourceId = (int) $sourceId;

            // Each dimension returns null for "no opinion" and an array for
            // "narrowed to these". Only the non-null ones constrain, which is
            // what keeps an unconfigured dimension from silently emptying the set.
            $bySignature = $index->candidatesBySignature($sourceId);
            $byCategory = $index->candidatesByCategory($sourceId);
            $byPrice = $percent > 0 ? $index->candidatesByPriceBand($sourceId, $percent) : null;

            // The list walked is one that is ALREADY in rank order - the
            // signature bucket, the category bucket, or the whole ranked pool -
            // so the walk can stop as soon as it has enough. The price band is
            // in price order, so it is only ever a filter, unless it is the one
            // constraint there is.
            $walk = $bySignature ?? $byCategory;
            $filters = [];
            if ($bySignature !== null && $byCategory !== null) {
                $filters[] = $byCategory;
            }
            if ($byPrice !== null) {
                if ($walk === null) {
                    $walk = $index->sortByRank($byPrice);
                } else {
                    $filters[] = $byPrice;
                }
            }
            $walk ??= $index->getAllIds();

            // Hash lookups against flipped sets, not array_intersect: that
            // casts every element to string, and this is the innermost loop of
            // the run.
            $lookups = array_map('array_flip', $filters);

            $candidates = [];
            foreach ($walk as $candidateId) {
                if ($candidateId === $sourceId) {
                    continue;
                }
                foreach ($lookups as $lookup) {
                    if (!isset($lookup[$candidateId])) {
                        continue 2;
                    }
                }
                $candidates[] = $candidateId;
                if (count($candidates) >= $needed) {
                    break;
                }
            }

            if ($candidates) {
                $result[$sourceId] = $this->picker->pick($rule, $sourceId, $candidates, $maxLinks);
            }
        }

        return $result;
    }
}
