<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\ResourceModel\Rule;

use Magenx\AutoProductLinks\Model\Rule;
use Magenx\AutoProductLinks\Model\ResourceModel\Rule as RuleResource;

/**
 * Collection of auto-product-link rules.
 */
class Collection extends \Magento\Rule\Model\ResourceModel\Rule\Collection\AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(Rule::class, RuleResource::class);
    }

    /**
     * Rules the cron should consider, in the order it must run them.
     *
     * sort_order first (lower wins a contested link slot), then rule_id so the
     * ordering is total and a run is reproducible - which is what stops two
     * equal-priority rules alternately claiming the same link and churning the
     * storefront cache every night.
     *
     * @param string|null $linkType
     * @return $this
     */
    public function addRunOrder(?string $linkType = null): self
    {
        if ($linkType !== null) {
            $this->addFieldToFilter('link_type', $linkType);
        }

        $this->getSelect()->order('sort_order ASC')->order('rule_id ASC');

        return $this;
    }

    /**
     * Only active rules.
     *
     * Date eligibility is NOT filtered here - it is evaluated per rule in
     * Rule::isRunnableNow(), which compares in the store timezone.
     *
     * @return $this
     */
    public function addActiveFilter(): self
    {
        $this->addFieldToFilter('is_active', 1);

        return $this;
    }
}
