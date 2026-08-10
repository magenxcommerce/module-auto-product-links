<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Config\Source;

use Magenx\AutoProductLinks\Model\Ranker;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * How the surviving candidates are ordered before the rule's limit is applied.
 */
class ResultSort implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Ranker::SORT_BESTSELLERS, 'label' => __('Best sellers first')],
            ['value' => Ranker::SORT_STRATEGY, 'label' => __('Strongest match first (bought-together strength)')],
            ['value' => Ranker::SORT_NEWEST, 'label' => __('Newest first')],
            ['value' => Ranker::SORT_PRICE_DESC, 'label' => __('Most expensive first')],
            ['value' => Ranker::SORT_PRICE_ASC, 'label' => __('Cheapest first')],
            ['value' => Ranker::SORT_RANDOM, 'label' => __('Random')],
        ];
    }
}
