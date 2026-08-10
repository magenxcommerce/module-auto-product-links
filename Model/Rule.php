<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model;

use Magento\CatalogWidget\Model\Rule\Condition\CombineFactory;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * An auto-product-link rule.
 *
 * Magento\Rule\Model\AbstractModel already carries TWO condition trees and
 * handles all of their persistence, which is the whole reason this class is
 * ~40 lines instead of several hundred:
 *
 *   getConditions() -> serialized into `conditions_serialized` = the SOURCE tree
 *                      (which products the rule applies to)
 *   getActions()    -> serialized into `actions_serialized`    = the TARGET tree
 *                      (the pool of products it may link to)
 *
 * beforeSave() serializes both, the lazy getters deserialize them, and
 * loadPost() maps them out of the admin POST. getActions() also calls
 * setPrefix('actions'), which is precisely what stops the two trees' form
 * element names colliding when both are rendered on one page. The two column
 * names above are fixed by the parent - they are not free choices.
 *
 * Both trees use the CatalogWidget condition classes rather than the CatalogRule
 * ones. The admin widget, the attribute list and the serialized format are
 * identical; the difference is that CatalogWidget's conditions implement
 * getMappedSqlField(), so a tree can be pushed down into a single SQL query
 * instead of validating every product in the catalog in PHP. See
 * Model\Rule\ProductMatcher.
 */
class Rule extends \Magento\Rule\Model\AbstractModel
{
    public const LINK_TYPE_RELATED = 'related';
    public const LINK_TYPE_UPSELL = 'upsell';
    public const LINK_TYPE_CROSSSELL = 'crosssell';

    public const STRATEGY_ATTRIBUTE_MATCH = 'attribute_match';
    public const STRATEGY_CO_PURCHASE = 'co_purchase';

    /** Pseudo attribute codes understood by the "must match the source" list. */
    public const MATCH_CATEGORY = '__category';
    public const MATCH_PRICE_BAND = '__price_band';

    /**
     * @param CombineFactory $combineFactory
     * @param Json $jsonSerializer Own instance: the parent's serializer property
     *        visibility is not part of its public contract, so it is not relied on.
     * @param Context $context
     * @param Registry $registry
     * @param FormFactory $formFactory
     * @param TimezoneInterface $localeDate
     * @param AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     * @param \Magento\Framework\Api\ExtensionAttributesFactory|null $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory|null $customAttributeFactory
     * @param Json|null $serializer
     */
    public function __construct(
        private readonly CombineFactory $combineFactory,
        private readonly Json $jsonSerializer,
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        TimezoneInterface $localeDate,
        ?AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = [],
        ?\Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory = null,
        ?\Magento\Framework\Api\AttributeValueFactory $customAttributeFactory = null,
        ?Json $serializer = null
    ) {
        parent::__construct(
            $context,
            $registry,
            $formFactory,
            $localeDate,
            $resource,
            $resourceCollection,
            $data,
            $extensionFactory,
            $customAttributeFactory,
            $serializer
        );
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        parent::_construct();
        $this->_init(ResourceModel\Rule::class);
        $this->setIdFieldName('rule_id');
    }

    /**
     * The SOURCE tree: which products this rule applies to.
     *
     * @return \Magento\Rule\Model\Condition\Combine
     */
    public function getConditionsInstance(): \Magento\Rule\Model\Condition\Combine
    {
        return $this->combineFactory->create();
    }

    /**
     * The TARGET tree: the pool of products this rule may link to.
     *
     * @return \Magento\Rule\Model\Condition\Combine
     */
    public function getActionsInstance(): \Magento\Rule\Model\Condition\Combine
    {
        return $this->combineFactory->create();
    }

    /**
     * Attribute codes a candidate must share with the source product, plus the
     * pseudo codes __category and __price_band. Stored as JSON because the admin
     * field is a multiselect.
     *
     * @return string[]
     */
    public function getMatchAttributes(): array
    {
        $raw = $this->getData('match_attributes');

        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        // Tolerate both the JSON we write and a plain comma-separated string,
        // which is what a direct SQL edit or a data patch is likely to leave.
        try {
            $decoded = $this->jsonSerializer->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            $decoded = explode(',', $raw);
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $decoded
        )));
    }

    /**
     * Whether the rule is runnable right now (active and inside its date range).
     *
     * Evaluated in PHP rather than SQL because the comparison has to happen in
     * the store's timezone, which is why the table has no from/to index.
     *
     * @return bool
     */
    public function isRunnableNow(): bool
    {
        if (!(bool) $this->getData('is_active')) {
            return false;
        }

        $today = $this->_localeDate->date()->format('Y-m-d');

        $from = $this->dateAsDay($this->getData('from_date'));
        if ($from !== null && $today < $from) {
            return false;
        }

        $to = $this->dateAsDay($this->getData('to_date'));
        if ($to !== null && $today > $to) {
            return false;
        }

        return true;
    }

    /**
     * from_date / to_date are a string ('Y-m-d H:i:s') when the rule came from
     * the database, but a \DateTime when it came from the admin POST -
     * Magento\Rule\Model\AbstractModel::_convertFlatToRecursive() converts them
     * on loadPost(). Casting either one with (string) throws for the object, so
     * both shapes are normalised to a Y-m-d day here.
     *
     * @param mixed $value
     * @return string|null
     */
    private function dateAsDay($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && trim($value) !== '') {
            return substr($value, 0, 10);
        }

        return null;
    }
}
