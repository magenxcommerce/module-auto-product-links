<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model;

/**
 * The half of a CandidateIndex that depends only on the rule and its candidate
 * pool - not on which source products are currently being processed.
 *
 * This split exists for one reason. A rule's source set is walked in batches
 * (batch_size, 100 by default) up to max_source_products_per_run (5000), so the
 * index used to be rebuilt fifty times per rule per night - and every rebuild
 * redid the whole pool side: one query per match attribute over the full
 * universe, the category membership query and its inversion, the price index
 * query, an O(n log n) sort of up to 5000 prices, and a re-rank of the pool
 * including the bestsellers aggregate over all 5000 candidate ids. None of that
 * depends on the batch. Computing it once per rule and folding in only the
 * source-side rows per batch removes roughly fifty times that work.
 *
 * Immutable and reusable across every batch of one rule. Discard it when the
 * rule changes: the ranking and the buckets are specific to that rule's
 * result_sort and match attributes.
 */
class PoolIndex
{
    /**
     * @param int[] $candidateIds the pool, normalised
     * @param int[] $ranked every candidate, in rank order
     * @param array<int, int> $rank candidateId => rank (0 = best)
     * @param array<string, int[]> $bySignature signature => candidateId[]
     * @param array<int, string> $signatureOf candidateId => signature
     * @param array<int, int[]> $byCategory categoryId => candidateId[]
     * @param array<int, int[]> $categoriesOf candidateId => categoryId[]
     * @param array<int, float> $price candidateId => price
     * @param array<int, int> $pricedIds ascending-by-price list of candidate ids
     * @param float[] $sortedPrices the prices of $pricedIds, same order
     * @param string[] $attributeCodes real attribute codes the rule matches on
     * @param bool $wantsCategory whether the rule selected __category
     * @param bool $wantsPrices whether prices are needed at all
     */
    public function __construct(
        public readonly array $candidateIds,
        public readonly array $ranked,
        public readonly array $rank,
        public readonly array $bySignature,
        public readonly array $signatureOf,
        public readonly array $byCategory,
        public readonly array $categoriesOf,
        public readonly array $price,
        public readonly array $pricedIds,
        public readonly array $sortedPrices,
        public readonly array $attributeCodes,
        public readonly bool $wantsCategory,
        public readonly bool $wantsPrices
    ) {
    }
}
