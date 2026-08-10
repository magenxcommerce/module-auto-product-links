<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Block\Adminhtml\Rule\Edit;

use Magenx\AutoProductLinks\Controller\Adminhtml\Rule as RuleController;
use Magenx\AutoProductLinks\Model\Config\Source\LinkType;
use Magenx\AutoProductLinks\Model\Config\Source\MatchAttributes;
use Magenx\AutoProductLinks\Model\Config\Source\ResultSort;
use Magenx\AutoProductLinks\Model\Config\Source\TargetStrategy;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Backend\Block\Widget\Form\Renderer\Fieldset;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;
use Magento\Rule\Block\Actions;
use Magento\Rule\Block\Conditions;
use Magento\Store\Model\System\Store as SystemStore;

/**
 * The rule form.
 *
 * A legacy Widget\Form\Generic, NOT a UI component, and that is a deliberate
 * choice. The condition tree is legacy Magento\Framework\Data\Form machinery;
 * getting it into a UI-component form needs an htmlContent node, a DataProvider
 * and a modifier pool, and is the single most common way a rule form ends up
 * rendering blank. Magento\Backend\Block\Widget\Form\Renderer\Fieldset (the
 * renderer CatalogRule and SalesRule both use - there is no
 * Magento\Rule\Model\Renderer\Fieldset) was written for this form, so each tree
 * drops in with a renderer and one field.
 *
 * Both trees live on one page. They do not collide because
 * Magento\Rule\Model\AbstractModel::getActions() sets the prefix "actions" on
 * the second one, which is exactly what the two setJsFormObject calls below rely
 * on.
 */
class Form extends Generic
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param FormFactory $formFactory
     * @param Conditions $conditionsBlock
     * @param Actions $actionsBlock
     * @param SystemStore $systemStore
     * @param LinkType $linkTypeSource
     * @param TargetStrategy $targetStrategySource
     * @param ResultSort $resultSortSource
     * @param MatchAttributes $matchAttributesSource
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly Conditions $conditionsBlock,
        private readonly Actions $actionsBlock,
        private readonly SystemStore $systemStore,
        private readonly LinkType $linkTypeSource,
        private readonly TargetStrategy $targetStrategySource,
        private readonly ResultSort $resultSortSource,
        private readonly MatchAttributes $matchAttributesSource,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        parent::_construct();
        $this->setId('magenx_auto_link_rule_form');
    }

    /**
     * @return $this
     */
    protected function _prepareForm(): self
    {
        /** @var \Magenx\AutoProductLinks\Model\Rule $model */
        $model = $this->_coreRegistry->registry(RuleController::REGISTRY_KEY);

        $form = $this->_formFactory->create([
            'data' => [
                'id' => 'edit_form',
                'action' => $this->getUrl('*/*/save'),
                'method' => 'post',
            ],
        ]);
        $form->setHtmlIdPrefix('rule_');

        $this->addGeneralFieldset($form, $model);
        $this->addTargetFieldset($form, $model);
        $this->addConditionsFieldset($form, $model);
        $this->addActionsFieldset($form, $model);

        $form->setValues($model->getData());
        $form->setUseContainer(true);
        $this->setForm($form);

        return parent::_prepareForm();
    }

    /**
     * @param \Magento\Framework\Data\Form $form
     * @param \Magenx\AutoProductLinks\Model\Rule $model
     * @return void
     */
    private function addGeneralFieldset($form, $model): void
    {
        $fieldset = $form->addFieldset('base_fieldset', ['legend' => __('Rule Information')]);

        if ($model->getId()) {
            $fieldset->addField('rule_id', 'hidden', ['name' => 'rule_id']);
        }

        $fieldset->addField('name', 'text', [
            'name' => 'name',
            'label' => __('Rule Name'),
            'title' => __('Rule Name'),
            'required' => true,
        ]);

        $fieldset->addField('description', 'textarea', [
            'name' => 'description',
            'label' => __('Description'),
            'title' => __('Description'),
            'style' => 'height: 80px',
        ]);

        $fieldset->addField('is_active', 'select', [
            'name' => 'is_active',
            'label' => __('Active'),
            'title' => __('Active'),
            'options' => [1 => __('Yes'), 0 => __('No')],
            'note' => __(
                'Switching a rule off removes the links it created on the next run. '
                . 'Links you added by hand are never affected.'
            ),
        ]);

        $fieldset->addField('link_type', 'select', [
            'name' => 'link_type',
            'label' => __('Fills'),
            'title' => __('Fills'),
            'required' => true,
            'values' => $this->linkTypeSource->toOptionArray(),
            'note' => __('Which of the product page\'s three link sections this rule populates.'),
        ]);

        $fieldset->addField('store_id', 'select', [
            'name' => 'store_id',
            'label' => __('Evaluate For Store View'),
            'title' => __('Evaluate For Store View'),
            'values' => $this->systemStore->getStoreValuesForForm(true, false),
            'note' => __(
                'Decides which store view\'s attribute values, prices and orders the rule reads. '
                . 'It does NOT limit who sees the links: Magento stores product links globally, '
                . 'with no store, website or customer group.'
            ),
        ]);

        $fieldset->addField('sort_order', 'text', [
            'name' => 'sort_order',
            'label' => __('Priority'),
            'title' => __('Priority'),
            'class' => 'validate-digits',
            'note' => __('Lower numbers run first and win when two rules want the same slot on a product.'),
        ]);

        $dateFormat = $this->_localeDate->getDateFormat(\IntlDateFormatter::SHORT);
        $fieldset->addField('from_date', 'date', [
            'name' => 'from_date',
            'label' => __('Active From'),
            'title' => __('Active From'),
            'date_format' => $dateFormat,
        ]);
        $fieldset->addField('to_date', 'date', [
            'name' => 'to_date',
            'label' => __('Active To'),
            'title' => __('Active To'),
            'date_format' => $dateFormat,
        ]);
    }

    /**
     * @param \Magento\Framework\Data\Form $form
     * @param \Magenx\AutoProductLinks\Model\Rule $model
     * @return void
     */
    private function addTargetFieldset($form, $model): void
    {
        $fieldset = $form->addFieldset('target_fieldset', ['legend' => __('How Links Are Chosen')]);

        $fieldset->addField('target_strategy', 'select', [
            'name' => 'target_strategy',
            'label' => __('Choose Products By'),
            'title' => __('Choose Products By'),
            'required' => true,
            'values' => $this->targetStrategySource->toOptionArray(),
            'note' => __(
                'Either products that resemble the one being viewed, or products that customers '
                . 'actually bought alongside it. Both are still limited to the candidate products '
                . 'you define further down.'
            ),
        ]);

        $fieldset->addField('match_attributes', 'multiselect', [
            'name' => 'match_attributes[]',
            'label' => __('Must Match the Source Product On'),
            'title' => __('Must Match the Source Product On'),
            'values' => $this->matchAttributesSource->toOptionArray(),
            'note' => __(
                'Optional. A linked product must have the same value as the product being viewed for '
                . 'every attribute selected here — pick Colour for "same colour", add Size for "same '
                . 'colour and size". Leave empty to accept any candidate product.'
            ),
        ]);

        $fieldset->addField('price_band_percent', 'text', [
            'name' => 'price_band_percent',
            'label' => __('Price Band (%)'),
            'title' => __('Price Band (%)'),
            'class' => 'validate-digits',
            'note' => __(
                'Used only when "Price within the band set below" is selected above. '
                . '20 means the linked product must cost within 20% either side of the product being viewed.'
            ),
        ]);

        $fieldset->addField('max_links', 'text', [
            'name' => 'max_links',
            'label' => __('Maximum Links per Product'),
            'title' => __('Maximum Links per Product'),
            'class' => 'validate-digits',
            'note' => __('Counts only links this rule creates. Your own links are additional and never capped.'),
        ]);

        $fieldset->addField('result_sort', 'select', [
            'name' => 'result_sort',
            'label' => __('Show Best'),
            'title' => __('Show Best'),
            'values' => $this->resultSortSource->toOptionArray(),
            'note' => __('Which candidates win the limited number of slots, and in what order they are stored.'),
        ]);
    }

    /**
     * A renderer block of its own for each tree.
     *
     * Both trees live on ONE form here (CatalogRule and SalesRule each put
     * theirs on a separate tab), so a single shared renderer instance is not
     * enough: setFieldSetId()/setNewChildUrl() are state on the block, and the
     * second fieldset would overwrite the first - both trees would then render
     * under the same JS object name and the source tree's "+" button would post
     * the target tree's form id. Core creates the block per fieldset for the
     * same reason; a constructor-injected one is a shared instance.
     *
     * @param string $fieldSetId
     * @return Fieldset
     */
    private function createTreeRenderer(string $fieldSetId): Fieldset
    {
        /** @var Fieldset $renderer */
        $renderer = $this->getLayout()->createBlock(Fieldset::class);
        $renderer->setTemplate('Magento_CatalogRule::promo/fieldset.phtml');
        // Magic setters (DataObject::__call) - the template reads them back with
        // getFieldSetId() / getNewChildUrl().
        $renderer->setNewChildUrl($this->getUrl('*/*/newConditionHtml', ['form' => $fieldSetId]));
        $renderer->setFieldSetId($fieldSetId);

        return $renderer;
    }

    /**
     * The SOURCE tree: which products this rule applies to.
     *
     * @param \Magento\Framework\Data\Form $form
     * @param \Magenx\AutoProductLinks\Model\Rule $model
     * @return void
     */
    private function addConditionsFieldset($form, $model): void
    {
        $renderer = $this->createTreeRenderer('rule_conditions_fieldset');

        $fieldset = $form->addFieldset('conditions_fieldset', [
            'legend' => __('Apply This Rule To These Products'),
        ])->setRenderer($renderer);

        $fieldset->addField('conditions', 'text', [
            'name' => 'conditions',
            'label' => __('Apply This Rule To These Products'),
            'title' => __('Apply This Rule To These Products'),
        ])->setRule($model)->setRenderer($this->conditionsBlock);
    }

    /**
     * The TARGET tree: the pool of products this rule may link to.
     *
     * @param \Magento\Framework\Data\Form $form
     * @param \Magenx\AutoProductLinks\Model\Rule $model
     * @return void
     */
    private function addActionsFieldset($form, $model): void
    {
        $renderer = $this->createTreeRenderer('rule_actions_fieldset');

        $fieldset = $form->addFieldset('actions_fieldset', [
            'legend' => __('Link To These Products'),
        ])->setRenderer($renderer);

        $fieldset->addField('actions', 'text', [
            'name' => 'actions',
            'label' => __('Link To These Products'),
            'title' => __('Link To These Products'),
            'note' => __(
                'The pool of products this rule may link to. Leave it empty to allow the whole catalog, '
                . 'or narrow it to, say, one accessories category.'
            ),
        ])->setRule($model)->setRenderer($this->actionsBlock);
    }
}
