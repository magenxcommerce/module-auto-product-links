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
        $result = [];

        foreach ($sourceIds as $sourceId) {
            $sourceId = (int) $sourceId;

            // Each dimension returns null for "no opinion" and an array for
            // "narrowed to these". Intersecting only the non-null ones is what
            // keeps an unconfigured dimension from silently emptying the set.
            $sets = array_filter(
                [
                    $index->candidatesBySignature($sourceId),
                    $index->candidatesByCategory($sourceId),
                    $percent > 0 ? $index->candidatesByPriceBand($sourceId, $percent) : null,
                ],
                static fn (?array $set): bool => $set !== null
            );

            if ($sets === []) {
                $candidates = $index->getAllIds();
            } else {
                $candidates = array_shift($sets);
                foreach ($sets as $set) {
                    $candidates = array_intersect($candidates, $set);
                    if (!$candidates) {
                        break;
                    }
                }
            }

            $candidates = array_values(array_diff($candidates, [$sourceId]));
            if (!$candidates) {
                continue;
            }

            $result[$sourceId] = array_slice($index->sortByRank($candidates), 0, $maxLinks);
        }

        return $result;
    }
}
