<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * Resource model for magenx_auto_link_rule.
 *
 * Extends Magento\Rule\Model\ResourceModel\AbstractResource, which already
 * serializes the `conditions` / `actions` trees into conditions_serialized and
 * actions_serialized on save and reverses it on load. That is the entire
 * persistence layer for both condition trees.
 */
class Rule extends \Magento\Rule\Model\ResourceModel\AbstractResource
{
    /**
     * @param Context $context
     * @param Ledger $ledger
     * @param Json $jsonSerializer Own instance under a distinct name: the parent
     *        chain (Magento\Framework\Model\ResourceModel\AbstractResource) already
     *        declares a non-readonly $serializer, and redeclaring it readonly here
     *        is a fatal error ("Cannot redeclare non-readonly property ... as
     *        readonly"). Do not rename this back to $serializer.
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        private readonly Ledger $ledger,
        private readonly Json $jsonSerializer,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('magenx_auto_link_rule', 'rule_id');
    }

    /**
     * Remove the rule's links from the catalog BEFORE the row goes.
     *
     * The ledger's rule_id foreign key cascades, so deleting the rule would drop
     * the ledger rows on its own - but NOT the catalog_product_link rows they
     * point at. Those would survive as links the module no longer recognises as
     * its own, permanently indistinguishable from a merchant's hand-picked ones.
     * Deleting the links first is the only order that leaves no orphans, and the
     * mistake is unrecoverable after the fact.
     *
     * @param AbstractModel $object
     * @return $this
     */
    protected function _beforeDelete(AbstractModel $object): self
    {
        $ruleId = (int) $object->getId();
        if ($ruleId > 0) {
            $this->ledger->releaseRule($ruleId);
        }

        return parent::_beforeDelete($object);
    }

    /**
     * Store the match-attribute multiselect as JSON.
     *
     * The admin posts an array; the column is text. Doing this here (rather than
     * in the controller) keeps a programmatic save - a data patch, a fixture -
     * writing the same shape the reader expects.
     *
     * PUBLIC, not protected: Magento\Rule\Model\ResourceModel\AbstractResource
     * widens _beforeSave() to public (that is where it serializes the condition
     * trees), so narrowing it back here is a fatal error. Do not "fix" this to
     * protected to match the other _before/_after hooks.
     *
     * @param AbstractModel $object
     * @return $this
     */
    public function _beforeSave(AbstractModel $object): self
    {
        $matchAttributes = $object->getData('match_attributes');
        if (is_array($matchAttributes)) {
            $clean = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $matchAttributes
            )));
            $object->setData('match_attributes', $clean === [] ? null : $this->jsonSerializer->serialize($clean));
        }

        // An empty price band means "no band", not "a band of zero percent".
        $band = $object->getData('price_band_percent');
        if ($band === '' || $band === null) {
            $object->setData('price_band_percent', null);
        }

        foreach (['from_date', 'to_date'] as $dateField) {
            if ($object->getData($dateField) === '') {
                $object->setData($dateField, null);
            }
        }

        // Runs LAST on purpose: the parent's resolveDate() turns the \DateTime
        // that loadPost() put on the model into a 'Y-m-d H:i:s' string (and
        // nulls anything that is neither), so nothing above may assume a string.
        return parent::_beforeSave($object);
    }
}
