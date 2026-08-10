<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Controller\Adminhtml\Rule;

use Magenx\AutoProductLinks\Controller\Adminhtml\Rule;
use Magenx\AutoProductLinks\Model\CacheInvalidator;
use Magenx\AutoProductLinks\Model\ResourceModel\Ledger;
use Magenx\AutoProductLinks\Model\RuleFactory;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;

/**
 * Deletes a rule, and with it every link the rule authored.
 *
 * The links are removed by the resource model's _beforeDelete (through the
 * ledger) BEFORE the row goes: the ledger's foreign key would otherwise cascade
 * the ownership records away and leave the catalog_product_link rows behind as
 * orphans the module can never recognise as its own again.
 */
class Delete extends Rule implements \Magento\Framework\App\Action\HttpPostActionInterface
{
    /**
     * @param Context $context
     * @param Registry $coreRegistry
     * @param RuleFactory $ruleFactory
     * @param Ledger $ledger
     * @param CacheInvalidator $cacheInvalidator
     */
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        RuleFactory $ruleFactory,
        private readonly Ledger $ledger,
        private readonly CacheInvalidator $cacheInvalidator
    ) {
        parent::__construct($context, $coreRegistry, $ruleFactory);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $ruleId = (int) $this->getRequest()->getParam('rule_id');

        if (!$ruleId) {
            $this->messageManager->addErrorMessage(__('We cannot find a rule to delete.'));

            return $redirect->setPath('*/*/');
        }

        try {
            $rule = $this->ruleFactory->create()->load($ruleId);
            if (!$rule->getId()) {
                throw new \Magento\Framework\Exception\LocalizedException(__('This rule no longer exists.'));
            }

            // Collected before the delete so the right product pages can be
            // refreshed afterwards - the ledger rows are gone by then.
            $touched = $this->ledger->releaseRule($ruleId);
            $rule->delete();

            if ($touched) {
                $this->cacheInvalidator->invalidateProducts($touched);
            }

            $this->messageManager->addSuccessMessage(
                __(
                    'You deleted the rule and removed the links it had created on %1 products. '
                    . 'Links you added by hand were not affected.',
                    count($touched)
                )
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $redirect->setPath('*/*/edit', ['rule_id' => $ruleId]);
        }

        return $redirect->setPath('*/*/');
    }
}
