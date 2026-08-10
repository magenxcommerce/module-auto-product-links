<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\ResourceModel\Rule\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Data source for the rule grid.
 *
 * A real class rather than a SearchResult virtualType because of the
 * links_written column: it is a correlated count over the ledger, and it is the
 * one number that tells a merchant at a glance whether a rule is doing anything
 * at all. Without it a rule that silently matches nothing looks identical to one
 * that is working.
 */
class Collection extends SearchResult
{
    /**
     * @param EntityFactoryInterface $entityFactory
     * @param LoggerInterface $logger
     * @param FetchStrategyInterface $fetchStrategy
     * @param ManagerInterface $eventManager
     * @param string $mainTable
     * @param string $resourceModel
     */
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        $mainTable = 'magenx_auto_link_rule',
        $resourceModel = \Magenx\AutoProductLinks\Model\ResourceModel\Rule::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    /**
     * @return $this
     */
    protected function _initSelect(): self
    {
        parent::_initSelect();

        // A correlated subquery, not a LEFT JOIN + GROUP BY: the grid is already
        // grouping/sorting/paging on the main table, and adding a GROUP BY here
        // would fight its own COUNT(*) for the pager. The ledger's
        // MAGENX_AUTO_LINK_LEDGER_RULE index makes each correlated lookup a
        // single index range scan, and the grid only ever shows one page.
        $ledgerTable = $this->getTable('magenx_auto_link_ledger');
        $this->getSelect()->columns([
            'links_written' => new \Zend_Db_Expr(
                '(SELECT COUNT(*) FROM ' . $this->getConnection()->quoteIdentifier($ledgerTable)
                . ' AS mall WHERE mall.rule_id = main_table.rule_id)'
            ),
        ]);

        return $this;
    }
}
