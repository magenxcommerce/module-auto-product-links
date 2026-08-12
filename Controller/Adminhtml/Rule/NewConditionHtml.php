<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Controller\Adminhtml\Rule;

use Magenx\AutoProductLinks\Controller\Adminhtml\Rule;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Rule\Model\Condition\AbstractCondition;

/**
 * Renders one newly added condition row.
 *
 * MANDATORY, and easy to miss: this is the endpoint the condition tree's "+"
 * button posts to. Without it, adding a condition in the rule form simply 404s
 * and the tree appears broken with nothing in the logs. Every Magento rule
 * entity ships one of these.
 *
 * It differs from the copy core ships in one respect. The requested class name
 * arrives in the request, and core instantiates it and only then checks that the
 * result is an AbstractCondition - by which point an arbitrary class's
 * constructor has already run. The check is done here BEFORE anything is
 * constructed. The route is ACL-gated either way, so this is defence in depth
 * rather than a fix for a known hole, but it costs one call.
 *
 * The instantiation itself deliberately stays on $this->_objectManager, exactly
 * as core does it: the class name is only known at runtime, and swapping in
 * Magento\Rule\Model\ConditionFactory would need verifying against a real
 * install before it is worth the regression risk on the tree's "+" button.
 */
class NewConditionHtml extends Rule
{
    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);

        $id = (string) $this->getRequest()->getParam('id');
        $formName = (string) $this->getRequest()->getParam('form');
        $typeArr = explode('|', str_replace('-', '/', (string) $this->getRequest()->getParam('type')));
        $type = $typeArr[0];

        // Checked before instantiation, not after. is_subclass_of() autoloads
        // the class but does not construct it, so a request naming something
        // outside the condition hierarchy never reaches a constructor.
        if ($type === '' || !is_subclass_of($type, AbstractCondition::class)) {
            return $result->setContents('');
        }

        /** @var AbstractCondition $model */
        $model = $this->_objectManager->create($type)
            ->setId($id)
            ->setType($type)
            ->setRule($this->ruleFactory->create())
            ->setPrefix($formName === 'rule_actions_fieldset' ? 'actions' : 'conditions');

        if (!empty($typeArr[1])) {
            $model->setAttribute($typeArr[1]);
        }

        $model->setJsFormObject($formName);

        return $result->setContents($model->asHtmlRecursive());
    }
}
