<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Config\Source;

use Magenx\AutoProductLinks\Model\Rule;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which link type a rule fills.
 *
 * The labels name the storefront surface as well as the Magento field. Cross-sells
 * and Frequently Bought Together are deliberately separate: cross-sells are the
 * merchant's own add-on suggestions shown in the cart, while Frequently Bought
 * Together is the product page rail fed from real order history.
 */
class LinkType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Rule::LINK_TYPE_RELATED, 'label' => __('Related Products ("You May Also Like")')],
            ['value' => Rule::LINK_TYPE_UPSELL, 'label' => __('Up-Sells ("We Also Recommend")')],
            ['value' => Rule::LINK_TYPE_CROSSSELL, 'label' => __('Cross-Sells (shown in the cart)')],
            [
                'value' => Rule::LINK_TYPE_BOUGHT_TOGETHER,
                'label' => __('Frequently Bought Together (from order history)'),
            ],
        ];
    }
}
