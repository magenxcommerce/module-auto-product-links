<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Controller\Adminhtml\Rule;

use Magenx\AutoProductLinks\Controller\Adminhtml\Rule;
use Magento\Framework\Controller\ResultInterface;

/**
 * The rule grid.
 */
class Index extends Rule
{
    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_PAGE);
        $result->setActiveMenu(self::ADMIN_RESOURCE);
        $result->addBreadcrumb(__('Marketing'), __('Marketing'));
        $result->addBreadcrumb(__('Auto Product Link Rules'), __('Auto Product Link Rules'));
        $result->getConfig()->getTitle()->prepend(__('Auto Product Link Rules'));

        return $result;
    }
}
