<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Link;

use Magenx\AutoProductLinks\Model\ResourceModel\Ledger;
use Magento\Catalog\Model\Product\Link;
use Magento\Framework\App\ResourceConnection;

/**
 * Writes auto links into catalog_product_link without ever disturbing a link a
 * merchant made by hand.
 *
 * Why raw SQL and not the catalog APIs:
 *
 *  - ProductLinkManagementInterface::setProductLinks() and
 *    Magento\Catalog\Model\ResourceModel\Product\Link::saveProductLinks() both
 *    REPLACE every link of the given type on the product. That is precisely the
 *    behaviour this module exists to avoid.
 *  - ProductLinkRepositoryInterface::save() is per link, but loads a full
 *    product model per call and fires product-save events - unusable for a
 *    catalog-wide nightly sweep.
 *
 * The diff is:
 *
 *     manual   = existing - owned          never touched, never re-positioned
 *     desired  = ruleOutput - manual       <- the line the guarantee rests on
 *     toDelete = owned - desired
 *     toInsert = desired - owned
 *
 * That subtraction of `manual` from `desired` is what covers the one case that
 * would otherwise break the promise: a rule computing a target the merchant had
 * already picked by hand. Without it the insert would be a no-op, the ledger
 * would claim the link anyway, and a later run whose rule no longer matched
 * would delete a merchant's link. With it, a hand-picked link never enters the
 * ledger, so it can never be released.
 */
class LinkWriter
{
    private const LINK_TABLE = 'catalog_product_link';
    private const POSITION_TABLE = 'catalog_product_link_attribute_int';

    /** Magento's stock link type ids, by our rule's link_type value. */
    private const LINK_TYPE_IDS = [
        \Magenx\AutoProductLinks\Model\Rule::LINK_TYPE_RELATED => Link::LINK_TYPE_RELATED,
        \Magenx\AutoProductLinks\Model\Rule::LINK_TYPE_UPSELL => Link::LINK_TYPE_UPSELL,
        \Magenx\AutoProductLinks\Model\Rule::LINK_TYPE_CROSSSELL => Link::LINK_TYPE_CROSSSELL,
    ];

    /**
     * @param ResourceConnection $resource
     * @param Ledger $ledger
     * @param PositionAttribute $positionAttribute
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Ledger $ledger,
        private readonly PositionAttribute $positionAttribute
    ) {
    }

    /**
     * Magento's numeric link type for one of our link_type values.
     *
     * @param string $linkType
     * @return int|null
     */
    public function resolveLinkTypeId(string $linkType): ?int
    {
        return self::LINK_TYPE_IDS[$linkType] ?? null;
    }

    /**
     * Apply one batch of computed links.
     *
     * @param int $ruleId
     * @param int $linkTypeId
     * @param array<int, int[]> $desiredBySource sourceProductId => linkedProductId[] in write order
     * @param int[] $sourceIds the whole batch, including sources the rule matched
     *        nothing for - they still need their previously-owned links released
     * @param int $positionBase
     * @param bool $dryRun
     * @return WriteResult
     */
    public function apply(
        int $ruleId,
        int $linkTypeId,
        array $desiredBySource,
        array $sourceIds,
        int $positionBase,
        bool $dryRun
    ): WriteResult {
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds))));
        if (!$sourceIds) {
            return new WriteResult();
        }

        $connection = $this->resource->getConnection();
        $existing = $this->loadExisting($sourceIds, $linkTypeId);
        $owned = $this->ledger->getOwned($sourceIds, $linkTypeId);

        $result = new WriteResult();
        $inserts = [];
        $ledgerRows = [];
        $touched = [];

        foreach ($sourceIds as $sourceId) {
            $ownedIds = $owned[$sourceId] ?? [];
            $existingIds = array_keys($existing[$sourceId] ?? []);
            $manual = array_values(array_diff($existingIds, $ownedIds));

            $computed = array_values(array_map('intval', $desiredBySource[$sourceId] ?? []));
            $desired = array_values(array_diff($computed, $manual));

            $result->manualSkipped += count($computed) - count($desired);

            $toDelete = array_values(array_diff($ownedIds, $desired));
            $toInsert = array_values(array_diff($desired, $ownedIds));

            if (!$toDelete && !$toInsert) {
                // Positions can still drift when the ranking changes without the
                // membership changing; they are rewritten below for every
                // desired link, so nothing is missed by skipping here.
                if ($desired) {
                    $result->unchanged += count($desired);
                }
                continue;
            }

            $touched[$sourceId] = true;
            $result->deleted += count($toDelete);
            $result->inserted += count($toInsert);

            if ($dryRun) {
                continue;
            }

            if ($toDelete) {
                $connection->delete($this->resource->getTableName(self::LINK_TABLE), [
                    'product_id = ?' => $sourceId,
                    'link_type_id = ?' => $linkTypeId,
                    'linked_product_id IN (?)' => $toDelete,
                ]);
                $this->ledger->release($sourceId, $linkTypeId, $toDelete);
            }

            foreach ($toInsert as $linkedId) {
                $inserts[] = [
                    'product_id' => $sourceId,
                    'linked_product_id' => $linkedId,
                    'link_type_id' => $linkTypeId,
                ];
            }

            foreach ($desired as $offset => $linkedId) {
                $ledgerRows[] = [
                    'rule_id' => $ruleId,
                    'product_id' => $sourceId,
                    'linked_product_id' => $linkedId,
                    'link_type_id' => $linkTypeId,
                    'position' => $positionBase + $offset,
                ];
            }
        }

        $result->touchedProductIds = array_keys($touched);

        if ($dryRun || (!$inserts && !$ledgerRows)) {
            return $result;
        }

        // INSERT IGNORE: catalog_product_link's own unique key on
        // (link_type_id, product_id, linked_product_id) makes a re-insert a
        // no-op rather than an error, which keeps a concurrent admin save from
        // failing the whole batch.
        if ($inserts) {
            $connection->insertOnDuplicate(
                $this->resource->getTableName(self::LINK_TABLE),
                $inserts,
                ['link_type_id']
            );
        }

        $this->writePositions($linkTypeId, $ledgerRows);
        $this->ledger->claim($ledgerRows);

        return $result;
    }

    /**
     * Existing links for a batch of sources.
     *
     * @param int[] $sourceIds
     * @param int $linkTypeId
     * @return array<int, array<int, int>> productId => [linkedProductId => linkId]
     */
    private function loadExisting(array $sourceIds, int $linkTypeId): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName(self::LINK_TABLE),
                    ['link_id', 'product_id', 'linked_product_id']
                )
                ->where('link_type_id = ?', $linkTypeId)
                ->where('product_id IN (?)', $sourceIds)
        );

        $existing = [];
        foreach ($rows as $row) {
            $existing[(int) $row['product_id']][(int) $row['linked_product_id']] = (int) $row['link_id'];
        }

        return $existing;
    }

    /**
     * Write the position of every desired link.
     *
     * The link ids are re-read for the whole batch in one query rather than
     * chased through LAST_INSERT_ID, which does not survive a multi-row insert.
     * Only links in $rows get a position, so a manual link's position is never
     * rewritten.
     *
     * @param int $linkTypeId
     * @param array<int, array{product_id:int, linked_product_id:int, position:int}> $rows
     * @return void
     */
    private function writePositions(int $linkTypeId, array $rows): void
    {
        if (!$rows) {
            return;
        }

        $attributeId = $this->positionAttribute->getAttributeId($linkTypeId);
        if ($attributeId === null) {
            return;
        }

        $sourceIds = array_values(array_unique(array_column($rows, 'product_id')));
        $existing = $this->loadExisting($sourceIds, $linkTypeId);

        $values = [];
        foreach ($rows as $row) {
            $linkId = $existing[$row['product_id']][$row['linked_product_id']] ?? null;
            if ($linkId === null) {
                continue;
            }
            $values[] = [
                'product_link_attribute_id' => $attributeId,
                'link_id' => $linkId,
                'value' => $row['position'],
            ];
        }

        if (!$values) {
            return;
        }

        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::POSITION_TABLE),
            $values,
            ['value']
        );
    }
}
