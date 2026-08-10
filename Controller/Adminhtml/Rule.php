<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Controller\Adminhtml;

use Magenx\AutoProductLinks\Model\RuleFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Registry;

/**
 * Shared plumbing for the rule admin controllers.
 */
abstract class Rule extends Action
{
    /**
     * Every action in this controller is gated on this resource. A role without
     * it sees neither the menu entry nor the routes.
     */
    public const ADMIN_RESOURCE = 'Magenx_AutoProductLinks::rules';

    /** Registry key the edit form block reads the current rule from. */
    public const REGISTRY_KEY = 'magenx_auto_link_rule';

    /**
     * @param Context $context
     * @param Registry $coreRegistry
     * @param RuleFactory $ruleFactory
     */
    public function __construct(
        Context $context,
        protected readonly Registry $coreRegistry,
        protected readonly RuleFactory $ruleFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Load the rule named by the request (a new, empty one when there is no id)
     * and put it in the registry for the form block.
     *
     * @return \Magenx\AutoProductLinks\Model\Rule|null null when an id was given
     *         but does not exist, after setting the error message
     */
    protected function loadRule(): ?\Magenx\AutoProductLinks\Model\Rule
    {
        $rule = $this->ruleFactory->create();
        $ruleId = (int) $this->getRequest()->getParam('rule_id');

        if ($ruleId) {
            $rule->load($ruleId);
            if (!$rule->getId()) {
                $this->messageManager->addErrorMessage(__('This rule no longer exists.'));

                return null;
            }
        }

        // Restore a failed submission so the merchant does not lose a condition
        // tree they just built.
        $data = $this->_getSession()->getData('magenx_auto_link_rule_data', true);
        if (!empty($data)) {
            $rule->addData($data);
        }

        $this->coreRegistry->register(self::REGISTRY_KEY, $rule);

        return $rule;
    }
}
