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
 * Builds the CandidateIndex a strategy resolves against.
 *
 * Two phases, and the split is the point:
 *
 *   buildPool()   once per rule - everything that depends only on the candidate
 *                 pool: the attribute buckets, the inverted category map, the
 *                 price-sorted list, and the ranking.
 *   withSources() once per batch - only the source-side rows, folded onto that
 *                 pool to produce the immutable CandidateIndex.
 *
 * Before the split the whole thing was rebuilt per batch, which on the shipped
 * defaults (5000 source products, batches of 100) meant fifty full rebuilds of
 * the pool side per rule per night - fifty runs of the bestsellers aggregate
 * over 5000 ids, fifty category inversions, fifty price sorts. Nothing in any of
 * it varied with the batch.
 *
 * Every dimension is still loaded in a bounded number of queries for a whole set
 * of ids - one query per match attribute, one for categories, one for prices.
 * Nothing here runs per product.
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
     * The rule-invariant half. Call once per rule, before the batch loop.
     *
     * @param Rule $rule
     * @param int[] $candidateIds the rule's target pool
     * @param int $storeId
     * @param int $websiteId
     * @return PoolIndex
     */
    public function buildPool(Rule $rule, array $candidateIds, int $storeId, int $websiteId): PoolIndex
    {
        $candidateIds = $this->normalize($candidateIds);

        $matchAttributes = $rule->getMatchAttributes();
        $attributeCodes = array_values(array_filter(
            $matchAttributes,
            static fn (string $code): bool => !str_starts_with($code, '__')
        ));
        $wantsCategory = in_array(Rule::MATCH_CATEGORY, $matchAttributes, true);
        $wantsPriceBand = in_array(Rule::MATCH_PRICE_BAND, $matchAttributes, true);
        $sort = (string) $rule->getData('result_sort');

        // --- signatures ---------------------------------------------------
        // One query per attribute for the whole pool, then a single string per
        // product. Grouping the candidates by that string is what turns the
        // per-source match into a hash lookup.
        $signatureOf = $this->signatures($attributeCodes, $candidateIds, $storeId);
        $bySignature = [];
        foreach ($candidateIds as $candidateId) {
            $signature = $signatureOf[$candidateId] ?? null;
            if ($signature !== null) {
                $bySignature[$signature][] = $candidateId;
            }
        }

        // --- categories -----------------------------------------------------
        $categoriesOf = [];
        $byCategory = [];
        if ($wantsCategory) {
            $categoriesOf = $this->categoryMembership->load($candidateIds);
            $byCategory = $this->categoryMembership->invert($categoriesOf);
        }

        // --- prices ---------------------------------------------------------
        // Loaded whenever a band is wanted, and also whenever the ranking is
        // price-based, so the two never load it twice.
        $wantsPrices = $wantsPriceBand || $this->ranker->needsPrices($sort);
        $prices = $wantsPrices ? $this->priceMap->load($candidateIds, $websiteId) : [];

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
        // surviving set by the precomputed rank. Note that SORT_RANDOM therefore
        // shuffles once per rule rather than once per batch - one consistent
        // order across the whole run, which is the more defensible reading of
        // "random" anyway.
        $ranked = $this->ranker->rank($sort, $candidateIds, $storeId, $prices);

        return new PoolIndex(
            $candidateIds,
            $ranked,
            array_flip($ranked),
            $bySignature,
            $signatureOf,
            $byCategory,
            $categoriesOf,
            $prices,
            $pricedIds,
            $sortedPrices,
            $attributeCodes,
            $wantsCategory,
            $wantsPrices
        );
    }

    /**
     * Fold one batch of source products onto a pool index.
     *
     * Loads only the source side. Sources already in the pool are not re-queried:
     * their rows are in the pool index already.
     *
     * @param PoolIndex $pool
     * @param int[] $sourceIds the batch of source products being processed
     * @param int $storeId
     * @param int $websiteId
     * @return CandidateIndex
     */
    public function withSources(PoolIndex $pool, array $sourceIds, int $storeId, int $websiteId): CandidateIndex
    {
        $sourceIds = $this->normalize($sourceIds);

        // A source that is also a candidate is already covered by the pool maps.
        // array_diff_key on flipped sets, not array_diff: the latter casts every
        // element to string to compare, and this runs once per batch against a
        // pool of thousands.
        $newIds = array_keys(array_diff_key(array_flip($sourceIds), array_flip($pool->candidateIds)));

        $signatureOf = $pool->signatureOf;
        $categoriesOf = $pool->categoriesOf;
        $prices = $pool->price;

        if ($newIds) {
            if ($pool->attributeCodes) {
                // + keeps the pool's entries: array union never overwrites an
                // existing key, and the two id sets are disjoint by construction.
                $signatureOf += $this->signatures($pool->attributeCodes, $newIds, $storeId);
            }
            if ($pool->wantsCategory) {
                $categoriesOf += $this->categoryMembership->load($newIds);
            }
            // Only the price BAND reads a source's own price. When prices were
            // loaded purely to rank the pool, pricedIds is empty, the band is
            // never consulted, and the source side would be a wasted query.
            if ($pool->pricedIds) {
                $prices += $this->priceMap->load($newIds, $websiteId);
            }
        }

        return new CandidateIndex(
            $pool->ranked,
            $pool->rank,
            $pool->bySignature,
            $signatureOf,
            $pool->byCategory,
            $categoriesOf,
            $prices,
            $pool->pricedIds,
            $pool->sortedPrices,
            // Whether the merchant ASKED for the dimension - never whether the
            // resulting bucket map happens to be non-empty. See CandidateIndex.
            $pool->attributeCodes !== [],
            $pool->wantsCategory
        );
    }

    /**
     * Concatenated match-attribute values, one string per product.
     *
     * A product missing a value for any required attribute gets no signature at
     * all, which is what excludes it from the rule on either side.
     *
     * @param string[] $attributeCodes
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int, string>
     */
    private function signatures(array $attributeCodes, array $productIds, int $storeId): array
    {
        if (!$attributeCodes || !$productIds) {
            return [];
        }

        $loaded = [];
        foreach ($attributeCodes as $code) {
            $loaded[$code] = $this->attributeValues->load($code, $productIds, $storeId);
        }

        $signatureOf = [];
        foreach ($productIds as $productId) {
            $parts = [];
            foreach ($attributeCodes as $code) {
                $value = $loaded[$code][$productId] ?? null;
                if ($value === null) {
                    // Missing a required dimension: the product cannot take part
                    // in this rule on either side.
                    $parts = null;
                    break;
                }
                $parts[] = $code . '=' . $value;
            }

            if ($parts !== null) {
                $signatureOf[$productId] = implode('|', $parts);
            }
        }

        return $signatureOf;
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
