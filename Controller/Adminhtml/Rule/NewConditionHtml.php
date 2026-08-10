<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Controller\Adminhtml\Rule;

use Magenx\AutoProductLinks\Controller\Adminhtml\Rule;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Renders one newly added condition row.
 *
 * MANDATORY, and easy to miss: this is the endpoint the condition tree's "+"
 * button posts to. Without it, adding a condition in the rule form simply 404s
 * and the tree appears broken with nothing in the logs. Every Magento rule
 * entity ships one of these; the implementation below is the standard one.
 */
class NewConditionHtml extends Rule
{
    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $id = (string) $this->getRequest()->getParam('id');
        $typeArr = explode('|', str_replace('-', '/', (string) $this->getRequest()->getParam('type')));
        $type = $typeArr[0];

        $model = $this->_objectManager->create($type)
            ->setId($id)
            ->setType($type)
            ->setRule($this->ruleFactory->create())
            ->setPrefix((string) $this->getRequest()->getParam('form') === 'rule_actions_fieldset'
                ? 'actions'
                : 'conditions');

        if (!empty($typeArr[1])) {
            $model->setAttribute($typeArr[1]);
        }

        $html = '';
        if ($model instanceof \Magento\Rule\Model\Condition\AbstractCondition) {
            $model->setJsFormObject((string) $this->getRequest()->getParam('form'));
            $html = $model->asHtmlRecursive();
        }

        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents($html);

        return $result;
    }
}
