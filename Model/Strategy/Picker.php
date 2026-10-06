<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Strategy;

use Magenx\AutoProductLinks\Model\Rule;

/**
 * Turns one source's ranked candidates into the links that get written.
 *
 * Normally that is just the best max_links. When a rule sets "pick from top N"
 * it is instead a random max_links out of the best N, which is how the default
 * cross-sell rule gives every product a different handful of the catalog's
 * cheapest products rather than the same eight everywhere.
 *
 * The random pick is a hash of (rule, source, candidate), not shuffle(): it has
 * to come out the same on every run, or every product's links would be
 * rewritten and its cache purged nightly with nothing really changed. The picks
 * are returned in rank order, so the cheapest of the chosen ones still leads.
 */
class Picker
{
    /**
     * How many leading candidates a strategy needs to collect for this rule.
     *
     * @param Rule $rule
     * @param int $maxLinks
     * @return int
     */
    public function needed(Rule $rule, int $maxLinks): int
    {
        return max($maxLinks, (int) $rule->getData('pick_from_top'));
    }

    /**
     * @param Rule $rule
     * @param int $sourceId
     * @param int[] $ranked candidates, best first, at least needed() long when available
     * @param int $maxLinks
     * @return int[]
     */
    public function pick(Rule $rule, int $sourceId, array $ranked, int $maxLinks): array
    {
        return $this->sample($rule, $sourceId, array_slice($ranked, 0, $this->needed($rule, $maxLinks)), $maxLinks);
    }

    /**
     * A stable random $maxLinks out of ALL of $candidates, kept in their given order.
     *
     * The same hash as pick(), without the "best N" cut-off. Costs one crc32 per
     * candidate, so it is meant for a bucket-sized list, not a whole catalog.
     *
     * @param Rule $rule
     * @param int $sourceId
     * @param int[] $candidates
     * @param int $maxLinks
     * @return int[]
     */
    public function sample(Rule $rule, int $sourceId, array $candidates, int $maxLinks): array
    {
        $top = array_values($candidates);
        if (count($top) <= $maxLinks) {
            return $top;
        }

        $seed = (int) $rule->getId() . ':' . $sourceId . ':';
        $score = [];
        foreach ($top as $position => $candidateId) {
            $score[$candidateId] = [crc32($seed . $candidateId), $position];
        }

        $chosen = $top;
        usort($chosen, static fn (int $a, int $b): int => $score[$a] <=> $score[$b]);
        $chosen = array_slice($chosen, 0, $maxLinks);
        usort($chosen, static fn (int $a, int $b): int => $score[$a][1] <=> $score[$b][1]);

        return $chosen;
    }
}
