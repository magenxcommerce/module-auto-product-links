<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Block\Adminhtml\Rule;

use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Registry;

/**
 * The rule edit page: title and buttons around the form.
 */
class Edit extends Container
{
    /**
     * @param Context $context
     * @param Registry $coreRegistry
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_objectId = 'rule_id';
        $this->_blockGroup = 'Magenx_AutoProductLinks';
        $this->_controller = 'adminhtml_rule';

        parent::_construct();

        $this->buttonList->update('save', 'label', __('Save Rule'));
        $this->buttonList->update('delete', 'label', __('Delete Rule'));

        $this->addButton(
            'save_and_continue',
            [
                'label' => __('Save and Continue Editing'),
                'class' => 'save',
                'data_attribute' => [
                    'mage-init' => ['button' => ['event' => 'saveAndContinueEdit', 'target' => '#edit_form']],
                ],
            ],
            10
        );

        $rule = $this->coreRegistry->registry(
            \Magenx\AutoProductLinks\Controller\Adminhtml\Rule::REGISTRY_KEY
        );

        // Only offered on a saved rule: running an unsaved one would silently
        // use the last saved conditions, which reads as the button not working.
        if ($rule && $rule->getId()) {
            // A POST, not setLocation(): the run action writes
            // catalog_product_link, and only POSTs get form-key validation. The
            // url/data pair is what Magento's own confirmSetLocation-free POST
            // buttons use, and it carries the form key automatically.
            $this->addButton(
                'run_now',
                [
                    'label' => __('Run Now'),
                    'class' => 'secondary',
                    'data_attribute' => [
                        'post' => [
                            'action' => $this->getUrl('*/*/run', ['rule_id' => $rule->getId()]),
                            'data' => ['rule_id' => $rule->getId()],
                        ],
                    ],
                ],
                20
            );
        }
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    public function getHeaderText(): \Magento\Framework\Phrase
    {
        $rule = $this->coreRegistry->registry(
            \Magenx\AutoProductLinks\Controller\Adminhtml\Rule::REGISTRY_KEY
        );

        if ($rule && $rule->getId()) {
            return __("Edit Rule '%1'", $this->escapeHtml((string) $rule->getData('name')));
        }

        return __('New Auto Product Link Rule');
    }
}
