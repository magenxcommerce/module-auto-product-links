<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Api;

use Magenx\AutoProductLinks\Model\CandidateIndex;
use Magenx\AutoProductLinks\Model\Rule;

/**
 * Decides WHICH products a rule links to.
 *
 * A strategy answers only that question. Everything around it - how many links
 * are allowed, what order they are written in, which links the module is allowed
 * to touch, dry-run, cache invalidation - is applied by the runner and the link
 * writer, so a new strategy inherits all of it for free.
 *
 * Contribute one through the `strategies` argument of
 * Magenx\AutoProductLinks\Model\Strategy\StrategyPool in di.xml. It then appears
 * in the rule form's dropdown and runs under every existing guard.
 */
interface TargetStrategyInterface
{
    /**
     * The value stored in the rule's target_strategy column.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * A short label for the admin dropdown.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getLabel(): \Magento\Framework\Phrase;

    /**
     * Pick link targets for each source product.
     *
     * Implementations must:
     *  - return targets drawn from the rule's candidate pool only (the index's
     *    getAllIds()), so a rule's target conditions always bind;
     *  - never return the source product itself;
     *  - return at most $maxLinks per source, best first.
     *
     * @param Rule $rule
     * @param int[] $sourceIds the batch being processed
     * @param CandidateIndex $index the rule's pre-bucketed candidate pool
     * @param int $maxLinks
     * @return array<int, int[]> sourceProductId => linkedProductId[] in write order
     */
    public function resolve(Rule $rule, array $sourceIds, CandidateIndex $index, int $maxLinks): array;
}
