<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Controller\Adminhtml\Rule;

use Magenx\AutoProductLinks\Controller\Adminhtml\Rule;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Saves a rule.
 */
class Save extends Rule implements \Magento\Framework\App\Action\HttpPostActionInterface
{
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

        $matchAttributes = $rule->getMatchAttributes();

        $band = (int) $rule->getData('price_band_percent');
        if (in_array(\Magenx\AutoProductLinks\Model\Rule::MATCH_PRICE_BAND, $matchAttributes, true) && $band <= 0) {
            throw new LocalizedException(
                __('Enter a price band percentage, or remove the price band from the matching rules.')
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
