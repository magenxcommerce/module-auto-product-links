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
 * The rule edit form.
 */
class Edit extends Rule
{
    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $rule = $this->loadRule();
        if ($rule === null) {
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

            return $redirect->setPath('*/*/');
        }

        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $result->setActiveMenu(self::ADMIN_RESOURCE);
        $result->addBreadcrumb(__('Auto Product Link Rules'), __('Auto Product Link Rules'), $this->getUrl('*/*/'));
        $result->getConfig()->getTitle()->prepend(
            $rule->getId() ? (string) $rule->getData('name') : __('New Auto Product Link Rule')
        );

        return $result;
    }
}
