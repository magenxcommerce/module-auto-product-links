<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Config\Source;

use Magenx\AutoProductLinks\Model\Rule;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Attributes a linked product must share with the source product.
 *
 * The list is the attributes flagged "Use for Promo Rule Conditions"
 * (catalog_eav_attribute.is_used_for_promo_rules, the same flag the Catalog and
 * Cart Price Rule condition pickers honour), so a merchant does not see an
 * attribute offered here and missing there.
 *
 * That flag has to be filtered by COLUMN: the product attribute collection
 * offers addIsFilterableFilter() / addVisibleFilter() / addIsSearchableFilter()
 * and friends, but there is no addIsUsedForPromoRulesFilter() in any Magento
 * version. The column lives on the joined additional_table, exactly like the
 * ones those methods filter on.
 *
 * Two pseudo-entries lead the list. They are not attributes; they are the two
 * "same as the source" dimensions that cannot be expressed as a single stored
 * value: category membership is multi-valued, and a price band is a range rather
 * than an equality.
 */
class MatchAttributes implements OptionSourceInterface
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [
            ['value' => Rule::MATCH_CATEGORY, 'label' => __('-- Shares a category with the source product --')],
            ['value' => Rule::MATCH_PRICE_BAND, 'label' => __('-- Price within the band set below --')],
        ];

        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('additional_table.is_used_for_promo_rules', 1);
            $collection->setOrder('frontend_label', 'ASC');

            foreach ($collection as $attribute) {
                $code = (string) $attribute->getAttributeCode();
                if ($code === '') {
                    continue;
                }
                $label = (string) $attribute->getFrontendLabel();
                $options[] = [
                    'value' => $code,
                    'label' => $label !== '' ? sprintf('%s (%s)', $label, $code) : $code,
                ];
            }
        } catch (\Exception $e) {
            // An unreadable attribute list must not blank the whole rule form -
            // the two pseudo-options above are still usable on their own.
            return $options;
        }

        return $options;
    }
}
