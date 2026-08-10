<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model;

use Magenx\AutoProductLinks\Model\SameAsSource\AttributeValues;
use Magenx\AutoProductLinks\Model\SameAsSource\CategoryMembership;
use Magenx\AutoProductLinks\Model\SameAsSource\PriceMap;

/**
 * Builds a CandidateIndex for one rule and one batch of source products.
 *
 * Every dimension is loaded in a bounded number of queries for the union of the
 * source batch and the candidate pool: one query per match attribute, one for
 * categories, one for prices. Nothing here runs per product.
 */
class CandidateIndexBuilder
{
    /**
     * @param AttributeValues $attributeValues
     * @param CategoryMembership $categoryMembership
     * @param PriceMap $priceMap
     * @param Ranker $ranker
     */
    public function __construct(
        private readonly AttributeValues $attributeValues,
        private readonly CategoryMembership $categoryMembership,
        private readonly PriceMap $priceMap,
        private readonly Ranker $ranker
    ) {
    }

    /**
     * @param Rule $rule
     * @param int[] $candidateIds the rule's target pool
     * @param int[] $sourceIds the batch of source products being processed
     * @param int $storeId
     * @param int $websiteId
     * @return CandidateIndex
     */
    public function build(
        Rule $rule,
        array $candidateIds,
        array $sourceIds,
        int $storeId,
        int $websiteId
    ): CandidateIndex {
        $candidateIds = $this->normalize($candidateIds);
        $sourceIds = $this->normalize($sourceIds);
        $universe = array_values(array_unique(array_merge($candidateIds, $sourceIds)));

        $matchAttributes = $rule->getMatchAttributes();
        $attributeCodes = array_values(array_filter(
            $matchAttributes,
            static fn (string $code): bool => !str_starts_with($code, '__')
        ));
        $wantsCategory = in_array(Rule::MATCH_CATEGORY, $matchAttributes, true);
        $wantsPriceBand = in_array(Rule::MATCH_PRICE_BAND, $matchAttributes, true);

        // --- signatures -------------------------------------------------
        // One query per attribute for the whole universe, then a single string
        // per product. Grouping the candidates by that string is what turns the
        // per-source match into a hash lookup.
        $signatureOf = [];
        $bySignature = [];
        if ($attributeCodes) {
            $loaded = [];
            foreach ($attributeCodes as $code) {
                $loaded[$code] = $this->attributeValues->load($code, $universe, $storeId);
            }

            foreach ($universe as $productId) {
                $parts = [];
                foreach ($attributeCodes as $code) {
                    $value = $loaded[$code][$productId] ?? null;
                    if ($value === null) {
                        // Missing a required dimension: the product cannot take
                        // part in this rule on either side.
                        $parts = null;
                        break;
                    }
                    $parts[] = $code . '=' . $value;
                }

                if ($parts !== null) {
                    $signatureOf[$productId] = implode('|', $parts);
                }
            }

            foreach ($candidateIds as $candidateId) {
                $signature = $signatureOf[$candidateId] ?? null;
                if ($signature !== null) {
                    $bySignature[$signature][] = $candidateId;
                }
            }
        }

        // --- categories -------------------------------------------------
        $categoriesOf = [];
        $byCategory = [];
        if ($wantsCategory) {
            $categoriesOf = $this->categoryMembership->load($universe);
            $candidateSet = array_flip($candidateIds);
            $candidateMembership = array_intersect_key($categoriesOf, $candidateSet);
            $byCategory = $this->categoryMembership->invert($candidateMembership);
        }

        // --- prices -----------------------------------------------------
        // Loaded whenever a band is wanted, and also whenever the ranking is
        // price-based, so the two never load it twice.
        $prices = [];
        if ($wantsPriceBand || $this->ranker->needsPrices((string) $rule->getData('result_sort'))) {
            $prices = $this->priceMap->load($universe, $websiteId);
        }

        $pricedIds = [];
        $sortedPrices = [];
        if ($wantsPriceBand && $prices) {
            foreach ($candidateIds as $candidateId) {
                if (isset($prices[$candidateId])) {
                    $pricedIds[] = $candidateId;
                }
            }
            usort($pricedIds, static fn (int $a, int $b): int => $prices[$a] <=> $prices[$b]);
            $sortedPrices = array_map(static fn (int $id): float => $prices[$id], $pricedIds);
        }

        // --- ranking ----------------------------------------------------
        // The pool is ranked ONCE here; per source we only ever sort the small
        // surviving set by the precomputed rank.
        $ranked = $this->ranker->rank(
            (string) $rule->getData('result_sort'),
            $candidateIds,
            $storeId,
            $prices
        );
        $rank = array_flip($ranked);

        return new CandidateIndex(
            $ranked,
            $rank,
            $bySignature,
            $signatureOf,
            $byCategory,
            $categoriesOf,
            $prices,
            $pricedIds,
            $sortedPrices
        );
    }

    /**
     * @param array $ids
     * @return int[]
     */
    private function normalize(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
