<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Controller\Adminhtml\Rule;

use Magenx\AutoProductLinks\Controller\Adminhtml\Rule;
use Magenx\AutoProductLinks\Model\Config;
use Magenx\AutoProductLinks\Model\RuleFactory;
use Magenx\AutoProductLinks\Model\RuleRunner;
use Magenx\AutoProductLinks\Model\CoPurchaseMiner;
use Magenx\AutoProductLinks\Model\Rule as RuleModel;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;

/**
 * "Run Now" from a rule's edit page.
 *
 * Runs every rule of the rule's LINK TYPE, not the rule alone: which products a
 * rule owns depends on the higher-priority rules of the same type (see
 * RuleRunner), so running one in isolation would write a result the next cron
 * run immediately overturns. A rule that reads order history refreshes the
 * co-purchase aggregate first, so it does not run from an empty one.
 *
 * POST-only, like Save and Delete. This action writes catalog_product_link, and
 * Magento only form-key-validates state-changing admin requests that arrive as
 * POST; a GET one leans entirely on admin secret keys, which are routinely
 * switched off behind SSO. A GET catalog write is also bookmarkable and
 * prefetchable by the browser. The button in Block\Adminhtml\Rule\Edit posts
 * accordingly - do not revert it to setLocation().
 */
class Run extends Rule implements HttpPostActionInterface
{
    /**
     * @param Context $context
     * @param Registry $coreRegistry
     * @param RuleFactory $ruleFactory
     * @param RuleRunner $runner
     * @param CoPurchaseMiner $miner
     * @param Config $config
     */
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        RuleFactory $ruleFactory,
        private readonly RuleRunner $runner,
        private readonly CoPurchaseMiner $miner,
        private readonly Config $config
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
            $this->messageManager->addErrorMessage(__('We cannot find a rule to run.'));

            return $redirect->setPath('*/*/');
        }

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(
                __('Auto Product Links is switched off in Stores > Configuration > Magenx > Auto Product Links.')
            );

            return $redirect->setPath('*/*/edit', ['rule_id' => $ruleId]);
        }

        try {
            $rule = $this->ruleFactory->create()->load($ruleId);
            if (!$rule->getId()) {
                $this->messageManager->addErrorMessage(__('This rule no longer exists.'));

                return $redirect->setPath('*/*/');
            }

            if ((string) $rule->getData('target_strategy') === RuleModel::STRATEGY_CO_PURCHASE) {
                $this->miner->mine();
            }

            $result = $this->runner->run((string) $rule->getData('link_type'));

            if ($this->config->isDryRun()) {
                $this->messageManager->addWarningMessage(
                    __(
                        'Dry run is on, so nothing was written. The rules of this link type would have added %1 '
                        . 'and removed %2 links, and would have left %3 of your own links untouched.',
                        $result->inserted,
                        $result->deleted,
                        $result->manualSkipped
                    )
                );
            } else {
                $this->messageManager->addSuccessMessage(
                    __(
                        'Ran every rule of this link type: added %1 and removed %2 links, '
                        . 'leaving %3 of your own links untouched.',
                        $result->inserted,
                        $result->deleted,
                        $result->manualSkipped
                    )
                );
            }
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Something went wrong while running the rule.'));
        }

        return $redirect->setPath('*/*/edit', ['rule_id' => $ruleId]);
    }
}
