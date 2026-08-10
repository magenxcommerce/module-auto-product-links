<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Config\Source;

use Magenx\AutoProductLinks\Model\Strategy\StrategyPool;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * How a rule picks its targets.
 *
 * Built from the strategy pool rather than a hardcoded list, so a strategy
 * contributed by another module through di.xml appears in the form with no
 * change here.
 */
class TargetStrategy implements OptionSourceInterface
{
    /**
     * @param StrategyPool $strategyPool
     */
    public function __construct(
        private readonly StrategyPool $strategyPool
    ) {
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->strategyPool->getAll() as $code => $strategy) {
            $options[] = ['value' => $code, 'label' => $strategy->getLabel()];
        }

        return $options;
    }
}
