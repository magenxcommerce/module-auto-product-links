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
 * Links to a random handful of the candidate pool, a different handful per
 * source product.
 *
 * This is what cart cross-sells usually are: not products that resemble the one
 * in the cart, just add-ons from a pool the merchant chose in the target
 * conditions. The match attributes and price band still apply, as filters, so
 * "random from the same category" is one rule. "Randomly pick from the best N"
 * narrows the draw to the best N under "Show best" first; without it the draw
 * is over every candidate. Either way the chosen links are stored in "Show
 * best" order.
 *
 * Like every random choice in this module the pick is a hash, not shuffle(), so
 * it comes out the same on every run and nothing is rewritten (or purged from
 * cache) just because a run happened. Over the whole, unfiltered pool it is a
 * hash ring: each candidate sits at a hashed point, each source draws hashed
 * points and takes the next candidate round the ring. That is O(links x log
 * pool) per source rather than a hash per candidate per source, and a product
 * added to or removed from the pool only moves the picks that landed on it or
 * next to it, instead of reshuffling the whole catalog.
 */
class Random implements TargetStrategyInterface
{
    /**
     * Below this many candidates per link, hashing every candidate is as cheap
     * as the ring and never runs out of distinct draws.
     */
    private const SMALL_POOL_FACTOR = 4;

    /** Ring draws per link before giving up on finding distinct candidates. */
    private const DRAWS_PER_LINK = 16;

    /** @var array{key: string, points: int[], ids: int[]}|null the last rule's ring */
    private ?array $ring = null;

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
        return Rule::STRATEGY_RANDOM;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): \Magento\Framework\Phrase
    {
        return __('Random products (a stable random pick per product)');
    }

    /**
     * @inheritDoc
     */
    public function resolve(Rule $rule, array $sourceIds, CandidateIndex $index, int $maxLinks): array
    {
        $percent = (int) $rule->getData('price_band_percent');
        $pickFromTop = (int) $rule->getData('pick_from_top');
        $limit = $pickFromTop > 0 ? $this->picker->needed($rule, $maxLinks) : PHP_INT_MAX;
        $result = [];

        foreach ($sourceIds as $sourceId) {
            $sourceId = (int) $sourceId;

            $bySignature = $index->candidatesBySignature($sourceId);
            $byCategory = $index->candidatesByCategory($sourceId);
            $byPrice = $percent > 0 ? $index->candidatesByPriceBand($sourceId, $percent) : null;

            if ($bySignature === null && $byCategory === null && $byPrice === null && $limit === PHP_INT_MAX) {
                $chosen = $this->drawFromRing($rule, $sourceId, $index, $maxLinks);
                if ($chosen) {
                    $result[$sourceId] = $chosen;
                }
                continue;
            }

            // Same walk as AttributeMatch: a list already in rank order, with
            // the other dimensions as hash-lookup filters, so the "best N" of
            // "Randomly pick from the best" is simply its head.
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
                if (count($candidates) >= $limit) {
                    break;
                }
            }

            if ($candidates) {
                $result[$sourceId] = $this->picker->sample($rule, $sourceId, $candidates, $maxLinks);
            }
        }

        return $result;
    }

    /**
     * A stable random $maxLinks of the whole pool, in rank order.
     *
     * @param Rule $rule
     * @param int $sourceId
     * @param CandidateIndex $index
     * @param int $maxLinks
     * @return int[]
     */
    private function drawFromRing(Rule $rule, int $sourceId, CandidateIndex $index, int $maxLinks): array
    {
        $allIds = $index->getAllIds();
        if (count($allIds) <= $maxLinks * self::SMALL_POOL_FACTOR + 1) {
            $candidates = array_values(array_filter($allIds, static fn (int $id): bool => $id !== $sourceId));

            return $this->picker->sample($rule, $sourceId, $candidates, $maxLinks);
        }

        ['points' => $points, 'ids' => $ids] = $this->ring($rule, $allIds);
        $size = count($points);
        $seed = (int) $rule->getId() . ':' . $sourceId . ':';

        $chosen = [];
        for ($draw = 0; count($chosen) < $maxLinks && $draw < $maxLinks * self::DRAWS_PER_LINK; $draw++) {
            $at = $this->successor($points, $this->hash($seed . $draw)) % $size;
            $candidateId = $ids[$at];
            if ($candidateId !== $sourceId) {
                $chosen[$candidateId] = $candidateId;
            }
        }

        return $index->sortByRank(array_values($chosen));
    }

    /**
     * The rule's pool placed on a hash ring, built once per rule and pool.
     *
     * withSources() hands every batch a fresh CandidateIndex over the same pool,
     * so the ring is keyed on the rule and the pool rather than on the index.
     * Only the latest one is kept: rules run one after another.
     *
     * @param Rule $rule
     * @param int[] $allIds
     * @return array{key: string, points: int[], ids: int[]}
     */
    private function ring(Rule $rule, array $allIds): array
    {
        $key = (int) $rule->getId() . ':' . count($allIds) . ':' . crc32(implode(',', $allIds));
        if ($this->ring !== null && $this->ring['key'] === $key) {
            return $this->ring;
        }

        $seed = (int) $rule->getId() . ':';
        $hashes = [];
        foreach ($allIds as $id) {
            $hashes[$id] = $this->hash($seed . $id);
        }
        asort($hashes);

        $this->ring = [
            'key' => $key,
            'points' => array_values($hashes),
            'ids' => array_keys($hashes),
        ];

        return $this->ring;
    }

    /**
     * A 32-bit hash that, unlike crc32(), scatters near-identical inputs.
     *
     * crc32 is linear: "7:1001:0" and "7:1002:0" land close together, so
     * neighbouring products would draw near-identical points and get
     * near-identical links, and consecutive ids would bunch up on the ring.
     *
     * @param string $value
     * @return int
     */
    private function hash(string $value): int
    {
        return unpack('N', md5($value, true))[1];
    }

    /**
     * Index of the first point >= $value; count($points) when there is none,
     * which the caller wraps round to 0.
     *
     * @param int[] $points ascending
     * @param int $value
     * @return int
     */
    private function successor(array $points, int $value): int
    {
        $low = 0;
        $high = count($points);
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($points[$mid] < $value) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }
}
