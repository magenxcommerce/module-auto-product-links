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

    /** @var int[] source products whose links changed, for cache invalidation */
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

        if ($other->touchedProductIds) {
            $this->touchedProductIds = array_merge($this->touchedProductIds, $other->touchedProductIds);
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
