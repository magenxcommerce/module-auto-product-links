<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed access to the Auto Product Links configuration
 * (Stores -> Configuration -> Magenx -> Auto Product Links).
 *
 * Resolvers and crons read config through here, never through
 * ScopeConfigInterface inline, so every default lives in one place and a blank
 * or zero admin value falls back to something sane instead of disabling the
 * feature by accident.
 */
class Config
{
    private const XML_PATH_ENABLED = 'magenx_auto_product_links/general/enabled';
    private const XML_PATH_DRY_RUN = 'magenx_auto_product_links/general/dry_run';
    private const XML_PATH_INVALIDATE_CACHE = 'magenx_auto_product_links/general/invalidate_cache';
    private const XML_PATH_MAX_LINKS_DEFAULT = 'magenx_auto_product_links/general/max_links_default';
    private const XML_PATH_AUTO_POSITION_BASE = 'magenx_auto_product_links/general/auto_position_base';

    private const XML_PATH_MAX_SOURCE_PER_RUN = 'magenx_auto_product_links/limits/max_source_products_per_run';
    private const XML_PATH_TARGET_POOL_CAP = 'magenx_auto_product_links/limits/target_pool_cap';
    private const XML_PATH_BATCH_SIZE = 'magenx_auto_product_links/limits/batch_size';

    private const XML_PATH_LOOKBACK_MONTHS = 'magenx_auto_product_links/copurchase/lookback_months';
    private const XML_PATH_MIN_SUPPORT = 'magenx_auto_product_links/copurchase/min_support';
    private const XML_PATH_MAX_ORDERS_PER_RUN = 'magenx_auto_product_links/copurchase/max_orders_per_run';
    private const XML_PATH_MAX_ITEMS_PER_ORDER = 'magenx_auto_product_links/copurchase/max_items_per_order';

    /** Fallbacks used when the stored value is missing, blank or zero. */
    private const DEFAULT_MAX_LINKS = 8;
    private const DEFAULT_POSITION_BASE = 1000;
    private const DEFAULT_MAX_SOURCE_PER_RUN = 5000;
    private const DEFAULT_TARGET_POOL_CAP = 5000;
    private const DEFAULT_BATCH_SIZE = 100;
    private const DEFAULT_LOOKBACK_MONTHS = 6;
    private const DEFAULT_MIN_SUPPORT = 3;
    private const DEFAULT_MAX_ORDERS_PER_RUN = 100000;
    private const DEFAULT_MAX_ITEMS_PER_ORDER = 50;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Master switch. Both crons return immediately when this is off.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Compute every change and log it, but write nothing - not even the ledger.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDryRun(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DRY_RUN, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Whether to broadcast cat_p_<id> cache identities after a run.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldInvalidateCache(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_INVALIDATE_CACHE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Link cap used when a rule leaves its own max_links empty.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getDefaultMaxLinks(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_PATH_MAX_LINKS_DEFAULT, self::DEFAULT_MAX_LINKS, $storeId);
    }

    /**
     * Position auto links are numbered from, so manual links always sort first.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getAutoPositionBase(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_AUTO_POSITION_BASE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // 0 is a legitimate choice here (a store with no manual links at all),
        // so only a negative value falls back.
        return $value >= 0 ? $value : self::DEFAULT_POSITION_BASE;
    }

    /**
     * Source products processed per rule per run, before the cursor pauses.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMaxSourceProductsPerRun(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_PATH_MAX_SOURCE_PER_RUN, self::DEFAULT_MAX_SOURCE_PER_RUN, $storeId);
    }

    /**
     * Hard cap on how many products a rule's target conditions may resolve to.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getTargetPoolCap(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_PATH_TARGET_POOL_CAP, self::DEFAULT_TARGET_POOL_CAP, $storeId);
    }

    /**
     * Source products written per database transaction.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getBatchSize(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_PATH_BATCH_SIZE, self::DEFAULT_BATCH_SIZE, $storeId);
    }

    /**
     * How many months of co-purchase history are kept and summed.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getLookbackMonths(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_PATH_LOOKBACK_MONTHS, self::DEFAULT_LOOKBACK_MONTHS, $storeId);
    }

    /**
     * Orders a pair must appear in before it counts.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMinSupport(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_PATH_MIN_SUPPORT, self::DEFAULT_MIN_SUPPORT, $storeId);
    }

    /**
     * Safety bound on the mining query.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMaxOrdersPerRun(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_PATH_MAX_ORDERS_PER_RUN, self::DEFAULT_MAX_ORDERS_PER_RUN, $storeId);
    }

    /**
     * Orders with more lines than this are skipped by the miner: the pair count
     * grows with the square of the line count, so one very large order would
     * otherwise dominate the whole aggregate.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMaxItemsPerOrder(?int $storeId = null): int
    {
        return $this->positiveInt(self::XML_PATH_MAX_ITEMS_PER_ORDER, self::DEFAULT_MAX_ITEMS_PER_ORDER, $storeId);
    }

    /**
     * First day of the oldest month still inside the co-purchase look-back window.
     *
     * Lives here because the miner (which prunes anything older) and the
     * co-purchase strategy (which sums anything newer) have to agree on it
     * exactly; they previously carried byte-identical private copies, which is
     * one edit away from the reader and the writer disagreeing about what is
     * still in the window.
     *
     * Anchored on the store's "now" so the window matches the merchant's idea of
     * a month, then formatted as a bare day - the value is compared against the
     * `period` date column, not against a timestamp.
     *
     * @param int|null $storeId
     * @return string Y-m-01
     */
    public function getCoPurchaseCutoffPeriod(?int $storeId = null): string
    {
        $months = max(1, $this->getLookbackMonths($storeId));

        return $this->timezone->date()
            ->modify('first day of this month')
            ->modify('-' . ($months - 1) . ' months')
            ->format('Y-m-01');
    }

    /**
     * Read a positive integer, falling back when the stored value is blank or <= 0.
     *
     * @param string $path
     * @param int $fallback
     * @param int|null $storeId
     * @return int
     */
    private function positiveInt(string $path, int $fallback, ?int $storeId): int
    {
        $value = (int) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : $fallback;
    }
}
