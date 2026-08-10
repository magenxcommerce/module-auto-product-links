<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Config\Source;

use Magenx\AutoProductLinks\Model\Rule;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which of Magento's three link types a rule fills.
 *
 * The labels name the storefront surface as well as the Magento field, because
 * "Cross-Sells" is what Magento calls the relationship while most storefronts
 * (this one included) present it as "Frequently Bought Together".
 */
class LinkType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Rule::LINK_TYPE_RELATED, 'label' => __('Related Products')],
            ['value' => Rule::LINK_TYPE_UPSELL, 'label' => __('Up-Sells')],
            ['value' => Rule::LINK_TYPE_CROSSSELL, 'label' => __('Cross-Sells (shown as "Frequently Bought Together")')],
        ];
    }
}
