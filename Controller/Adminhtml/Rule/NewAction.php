<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Controller\Adminhtml\Rule;

use Magenx\AutoProductLinks\Controller\Adminhtml\Rule;
use Magento\Framework\Controller\ResultInterface;

/**
 * "Add New Rule" - forwards to the edit form with no id.
 */
class NewAction extends Rule
{
    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $forward = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_FORWARD);

        return $forward->forward('edit');
    }
}
