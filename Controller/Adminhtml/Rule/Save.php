<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Controller\Adminhtml\Rule;

use Magenx\AutoProductLinks\Controller\Adminhtml\Rule;
use Magenx\AutoProductLinks\Model\Config\Source\LinkType;
use Magenx\AutoProductLinks\Model\Config\Source\ResultSort;
use Magenx\AutoProductLinks\Model\Config\Source\TargetStrategy;
use Magenx\AutoProductLinks\Model\RuleFactory;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;

/**
 * Saves a rule.
 */
class Save extends Rule implements \Magento\Framework\App\Action\HttpPostActionInterface
{
    /**
     * Upper bound on max_links. Not a technical limit - a rail nobody would ever
     * scroll, and a cheap way to catch a stray keystroke before it writes tens of
     * thousands of rows per source product.
     */
    private const MAX_LINKS_CEILING = 100;

    /** Sanity ceiling on the price band half-width. */
    private const MAX_PRICE_BAND_PERCENT = 1000;

    /**
     * @param Context $context
     * @param Registry $coreRegistry
     * @param RuleFactory $ruleFactory
     * @param LinkType $linkTypeSource
     * @param TargetStrategy $targetStrategySource
     * @param ResultSort $resultSortSource
     */
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        RuleFactory $ruleFactory,
        private readonly LinkType $linkTypeSource,
        private readonly TargetStrategy $targetStrategySource,
        private readonly ResultSort $resultSortSource
    ) {
        parent::__construct($context, $coreRegistry, $ruleFactory);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $data = $this->getRequest()->getPostValue();

        if (!$data) {
            return $redirect->setPath('*/*/');
        }

        try {
            $rule = $this->ruleFactory->create();
            $ruleId = (int) ($data['rule_id'] ?? 0);
            if ($ruleId) {
                $rule->load($ruleId);
                if (!$rule->getId()) {
                    throw new LocalizedException(__('This rule no longer exists.'));
                }
            }

            // loadPost() is what maps BOTH condition trees out of the POST -
            // core AbstractModel behaviour, so nothing here has to know how the
            // tree is encoded.
            $rule->loadPost($data);

            $this->validate($rule);
            $rule->save();

            $this->messageManager->addSuccessMessage(__('You saved the rule.'));
            $this->_getSession()->setData('magenx_auto_link_rule_data', false);

            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['rule_id' => $rule->getId()]);
            }

            return $redirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Something went wrong while saving the rule.'));
        }

        // Keep the submission so a rejected save does not throw away a condition
        // tree the merchant just built.
        $this->_getSession()->setData('magenx_auto_link_rule_data', $data);

        return $redirect->setPath('*/*/edit', ['rule_id' => (int) ($data['rule_id'] ?? 0)]);
    }

    /**
     * @param \Magenx\AutoProductLinks\Model\Rule $rule
     * @return void
     * @throws LocalizedException
     */
    private function validate(\Magenx\AutoProductLinks\Model\Rule $rule): void
    {
        if (trim((string) $rule->getData('name')) === '') {
            throw new LocalizedException(__('Enter a rule name.'));
        }

        $from = $this->toTimestamp($rule->getData('from_date'));
        $to = $this->toTimestamp($rule->getData('to_date'));
        if ($from !== null && $to !== null && $to < $from) {
            throw new LocalizedException(__('The end date cannot be earlier than the start date.'));
        }

        // The three enum columns. Without this a crafted or stale POST stores a
        // value nothing understands, the save succeeds, and the only symptom is a
        // "unknown link type" line in a log file at 3am - or, for result_sort,
        // silence, because the ranker falls through to its default. Validated
        // against the same option sources the form renders from, so the two can
        // never disagree.
        $this->assertOneOf($rule, 'link_type', $this->linkTypeSource->toOptionArray(), __('Fills'));
        $this->assertOneOf(
            $rule,
            'target_strategy',
            $this->targetStrategySource->toOptionArray(),
            __('Choose Products By')
        );
        $this->assertOneOf($rule, 'result_sort', $this->resultSortSource->toOptionArray(), __('Show Best'));

        // validate-digits in the form is client-side only and enforces no ceiling.
        $this->assertInRange($rule, 'max_links', 0, self::MAX_LINKS_CEILING, __('Maximum Links per Product'));
        $this->assertInRange($rule, 'sort_order', 0, PHP_INT_MAX, __('Priority'));
        $this->assertInRange(
            $rule,
            'price_band_percent',
            0,
            self::MAX_PRICE_BAND_PERCENT,
            __('Price Band (%)')
        );

        $matchAttributes = $rule->getMatchAttributes();

        $band = (int) $rule->getData('price_band_percent');
        if (in_array(\Magenx\AutoProductLinks\Model\Rule::MATCH_PRICE_BAND, $matchAttributes, true) && $band <= 0) {
            throw new LocalizedException(
                __('Enter a price band percentage, or remove the price band from the matching rules.')
            );
        }
    }

    /**
     * Reject a value that is not one of an option source's values.
     *
     * A blank is left alone: the column carries a database default, and the
     * required-field marking in the form already covers the ones that matter.
     *
     * @param \Magenx\AutoProductLinks\Model\Rule $rule
     * @param string $field
     * @param array<int, array{value: mixed}> $options
     * @param \Magento\Framework\Phrase $label
     * @return void
     * @throws LocalizedException
     */
    private function assertOneOf(
        \Magenx\AutoProductLinks\Model\Rule $rule,
        string $field,
        array $options,
        \Magento\Framework\Phrase $label
    ): void {
        $value = (string) $rule->getData($field);
        if ($value === '') {
            return;
        }

        $allowed = array_map(static fn (array $option): string => (string) $option['value'], $options);
        if (!in_array($value, $allowed, true)) {
            throw new LocalizedException(__('Choose a valid option for "%1".', $label));
        }
    }

    /**
     * Reject a numeric value outside its accepted range.
     *
     * @param \Magenx\AutoProductLinks\Model\Rule $rule
     * @param string $field
     * @param int $min
     * @param int $max
     * @param \Magento\Framework\Phrase $label
     * @return void
     * @throws LocalizedException
     */
    private function assertInRange(
        \Magenx\AutoProductLinks\Model\Rule $rule,
        string $field,
        int $min,
        int $max,
        \Magento\Framework\Phrase $label
    ): void {
        $raw = $rule->getData($field);
        if ($raw === null || $raw === '') {
            return;
        }

        if (!is_numeric($raw)) {
            throw new LocalizedException(__('"%1" must be a whole number.', $label));
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new LocalizedException(
                __('"%1" must be between %2 and %3.', $label, $min, $max)
            );
        }
    }

    /**
     * The active-from/to values are NOT strings here.
     *
     * Magento\Rule\Model\AbstractModel::_convertFlatToRecursive() - reached
     * through loadPost() - deliberately turns from_date / to_date into
     * \DateTime objects and sets them on the model; the resource model's
     * resolveDate() formats them back to a string on save. So casting them with
     * (string) throws "Object of class DateTime could not be converted to
     * string", and comparing them as strings would be wrong anyway: the admin
     * date field posts the locale's format (08/01/2026 for en_US), which does
     * not sort chronologically.
     *
     * @param mixed $value
     * @return int|null timestamp, or null when there is no usable date
     */
    private function toTimestamp($value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);

            return $timestamp === false ? null : $timestamp;
        }

        return null;
    }
}
