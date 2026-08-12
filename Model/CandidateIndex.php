<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model;

/**
 * An immutable, pre-bucketed view of one rule's candidate pool.
 *
 * This is the structure that makes "same colour as the source" cheap. The
 * condition tree is static, but a "must match the source" constraint is
 * per-source, so the obvious implementations are a query per source product
 * (O(N) round trips) or an in-memory scan of the pool per source
 * (O(sources x candidates)). Neither is acceptable on a real catalog.
 *
 * Instead the pool is grouped ONCE, by the concatenated values of the
 * match attributes ("color=13|manufacturer=7"), so a source's candidate set is a
 * single hash lookup. Categories are inverted the same way, and prices are held
 * in one array sorted by price so a band is a binary search rather than a scan.
 * Total cost is O(sources + candidates), and the per-source work is proportional
 * to the size of that source's own bucket - not to the size of the pool.
 */
class CandidateIndex
{
    /**
     * @param int[] $allIds every candidate, in rank order
     * @param array<int, int> $rank candidateId => rank (0 = best)
     * @param array<string, int[]> $bySignature signature => candidateId[]
     * @param array<int, string> $signatureOf productId => signature (sources included)
     * @param array<int, int[]> $byCategory categoryId => candidateId[]
     * @param array<int, int[]> $categoriesOf productId => categoryId[] (sources included)
     * @param array<int, float> $price productId => price (sources included)
     * @param array<int, int> $pricedIds ascending-by-price list of candidate ids
     * @param float[] $sortedPrices the prices of $pricedIds, same order (for bsearch)
     * @param bool $hasSignatureConstraint whether the rule selected any match attribute
     * @param bool $hasCategoryConstraint whether the rule selected __category
     */
    public function __construct(
        private readonly array $allIds,
        private readonly array $rank,
        private readonly array $bySignature,
        private readonly array $signatureOf,
        private readonly array $byCategory,
        private readonly array $categoriesOf,
        private readonly array $price,
        private readonly array $pricedIds,
        private readonly array $sortedPrices,
        private readonly bool $hasSignatureConstraint = false,
        private readonly bool $hasCategoryConstraint = false
    ) {
    }

    /**
     * @return int[]
     */
    public function getAllIds(): array
    {
        return $this->allIds;
    }

    /**
     * Candidates sharing the source's match-attribute signature.
     *
     * Returns null when no attribute constraint is configured, meaning "no
     * narrowing from this dimension" - which is different from an empty array,
     * which means "narrowed to nothing".
     *
     * @param int $sourceId
     * @return int[]|null
     */
    public function candidatesBySignature(int $sourceId): ?array
    {
        // Keyed on whether the rule ASKED for this dimension, not on whether the
        // bucket map came out non-empty. Inferring it from emptiness fails open:
        // a rule matching on colour, over a pool where no candidate has a colour
        // value, would read as "no attribute constraint configured" and link to
        // the entire pool. A misconfigured rule must link nothing.
        if (!$this->hasSignatureConstraint) {
            return null;
        }

        $signature = $this->signatureOf[$sourceId] ?? null;
        if ($signature === null) {
            // The source has no value for at least one required attribute, so
            // nothing can "match" it. Linking to everything would be worse than
            // linking to nothing.
            return [];
        }

        return $this->bySignature[$signature] ?? [];
    }

    /**
     * Candidates sharing at least one navigable category with the source.
     *
     * @param int $sourceId
     * @return int[]|null null when no category constraint is configured
     */
    public function candidatesByCategory(int $sourceId): ?array
    {
        // Same reasoning as candidatesBySignature(): an empty inverted map means
        // no candidate sits in a navigable category, which narrows to nothing -
        // it does not mean the merchant left the constraint off.
        if (!$this->hasCategoryConstraint) {
            return null;
        }

        $categoryIds = $this->categoriesOf[$sourceId] ?? [];
        if (!$categoryIds) {
            return [];
        }

        $union = [];
        foreach ($categoryIds as $categoryId) {
            foreach ($this->byCategory[$categoryId] ?? [] as $candidateId) {
                $union[$candidateId] = true;
            }
        }

        return array_keys($union);
    }

    /**
     * Candidates whose price is within +/- $percent of the source's.
     *
     * Binary search over the price-sorted list, so this is O(log n + matches)
     * rather than a scan of the pool.
     *
     * @param int $sourceId
     * @param int $percent
     * @return int[]|null null when the source (or the index) has no price
     */
    public function candidatesByPriceBand(int $sourceId, int $percent): ?array
    {
        // Deliberately asymmetric with the two dimensions above, which fail
        // closed. A price band has an external precondition they do not have -
        // the price index must have been built at least once - and an unindexed
        // catalog is a routine state (fresh install, reindex in flight) rather
        // than a misconfigured rule. Degrading to an unbanded match there beats
        // silently emptying every rule that uses a band. See PriceMap::load().
        if (!$this->pricedIds) {
            return null;
        }

        $sourcePrice = $this->price[$sourceId] ?? null;
        if ($sourcePrice === null || $sourcePrice <= 0) {
            return null;
        }

        $factor = max(0, $percent) / 100;
        $low = $sourcePrice * (1 - $factor);
        $high = $sourcePrice * (1 + $factor);

        $from = $this->lowerBound($low);
        $count = count($this->sortedPrices);

        $matches = [];
        for ($i = $from; $i < $count; $i++) {
            if ($this->sortedPrices[$i] > $high) {
                break;
            }
            $matches[] = $this->pricedIds[$i];
        }

        return $matches;
    }

    /**
     * Order candidate ids by the rule's chosen ranking, best first.
     *
     * The pool was ranked once when the index was built, so this is a lookup and
     * a sort of the (small) surviving set - never a re-ranking of the pool.
     *
     * @param int[] $candidateIds
     * @return int[]
     */
    public function sortByRank(array $candidateIds): array
    {
        usort($candidateIds, function (int $a, int $b): int {
            return ($this->rank[$a] ?? PHP_INT_MAX) <=> ($this->rank[$b] ?? PHP_INT_MAX);
        });

        return $candidateIds;
    }

    /**
     * First index whose price is >= $value.
     *
     * @param float $value
     * @return int
     */
    private function lowerBound(float $value): int
    {
        $lo = 0;
        $hi = count($this->sortedPrices);

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->sortedPrices[$mid] < $value) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }
}
