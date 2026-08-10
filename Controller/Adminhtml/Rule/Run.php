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
use Magenx\AutoProductLinks\Model\RunCursor;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;

/**
 * "Run Now" for a single rule.
 *
 * Runs ONE bounded batch synchronously - the same slice size the cron uses - and
 * says so, rather than pretending to have processed a whole catalog inside an
 * HTTP request. The cursor is reset first so the merchant sees the effect on the
 * beginning of the catalog, which is what they will go and check.
 */
class Run extends Rule
{
    /**
     * @param Context $context
     * @param Registry $coreRegistry
     * @param RuleFactory $ruleFactory
     * @param RuleRunner $runner
     * @param RunCursor $cursor
     * @param Config $config
     */
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        RuleFactory $ruleFactory,
        private readonly RuleRunner $runner,
        private readonly RunCursor $cursor,
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
            $this->cursor->reset($ruleId);
            $result = $this->runner->run($ruleId);

            if ($this->config->isDryRun()) {
                $this->messageManager->addWarningMessage(
                    __(
                        'Dry run is on, so nothing was written. The rule would have added %1 and removed %2 links, '
                        . 'and would have left %3 of your own links untouched.',
                        $result->inserted,
                        $result->deleted,
                        $result->manualSkipped
                    )
                );
            } else {
                $this->messageManager->addSuccessMessage(
                    __(
                        'Added %1 and removed %2 links, leaving %3 of your own links untouched. '
                        . 'Large catalogs continue in the background on the next scheduled run.',
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
