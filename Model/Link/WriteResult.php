<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Link;

/**
 * Tally of one batch of link writes, accumulated across a run for the log line.
 */
class WriteResult
{
    /** Links created. */
    public int $inserted = 0;

    /** Auto links removed because the rule no longer wants them. */
    public int $deleted = 0;

    /** Links already present and still wanted. */
    public int $unchanged = 0;

    /**
     * Computed targets dropped because the merchant had already linked them by
     * hand. This is the standing audit trail for the manual-link guarantee: it
     * counts every time the module declined to claim someone else's link.
     */
    public int $manualSkipped = 0;

    /**
     * Source products whose links were ACTUALLY changed, for cache invalidation.
     *
     * Empty on a dry run, and that is load-bearing rather than an oversight:
     * this list is the sole trigger for broadcasting cat_p_<id>, and a purge is
     * not a harmless side effect - it drops the identity from every configured
     * cache host and takes the headless storefront's rails with it. A run whose
     * whole contract is "changed nothing" must not do that. Nothing else reads
     * this field, so there is no report to keep it populated for.
     *
     * @var int[]
     */
    public array $touchedProductIds = [];

    /**
     * Fold another batch's tally into this one.
     *
     * @param WriteResult $other
     * @return void
     */
    public function merge(self $other): void
    {
        $this->inserted += $other->inserted;
        $this->deleted += $other->deleted;
        $this->unchanged += $other->unchanged;
        $this->manualSkipped += $other->manualSkipped;

        // Appended rather than array_merge()d: merge() is called once per batch,
        // and re-copying a list that grows to the per-run source cap on every
        // one of them is quadratic for no reason.
        foreach ($other->touchedProductIds as $productId) {
            $this->touchedProductIds[] = $productId;
        }
    }

    /**
     * @return string
     */
    public function summary(): string
    {
        return sprintf(
            'inserted=%d deleted=%d unchanged=%d manual_links_skipped=%d',
            $this->inserted,
            $this->deleted,
            $this->unchanged,
            $this->manualSkipped
        );
    }
}
