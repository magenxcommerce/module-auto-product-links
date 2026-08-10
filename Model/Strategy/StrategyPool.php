<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Strategy;

use Magenx\AutoProductLinks\Api\TargetStrategyInterface;

/**
 * Registry of target strategies, populated from di.xml.
 *
 * The module's extension point: another module adds a way of picking link
 * targets by contributing one item to the `strategies` argument, and it shows up
 * in the rule form and runs under every existing guard without touching the
 * runner.
 */
class StrategyPool
{
    /** @var array<string, TargetStrategyInterface> */
    private array $strategies = [];

    /**
     * @param TargetStrategyInterface[] $strategies
     * @throws \InvalidArgumentException
     */
    public function __construct(array $strategies = [])
    {
        foreach ($strategies as $name => $strategy) {
            if (!$strategy instanceof TargetStrategyInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Auto product link strategy "%s" must implement %s.',
                    (string) $name,
                    TargetStrategyInterface::class
                ));
            }
            $this->strategies[$strategy->getCode()] = $strategy;
        }
    }

    /**
     * @param string $code
     * @return TargetStrategyInterface|null
     */
    public function get(string $code): ?TargetStrategyInterface
    {
        return $this->strategies[$code] ?? null;
    }

    /**
     * @return array<string, TargetStrategyInterface>
     */
    public function getAll(): array
    {
        return $this->strategies;
    }
}
