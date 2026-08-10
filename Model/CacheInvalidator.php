<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;

/**
 * Tells Magento which products changed, so their cached pages are dropped.
 *
 * This module writes catalog_product_link directly, bypassing the product model,
 * so nothing invalidates anything on its own. Registering the identities and
 * dispatching clean_cache_by_tags is exactly what Magento's own indexers do
 * (see Magento\Indexer\Model\Processor\CleanCache).
 *
 * That single dispatch is also the whole storefront story. It produces
 * cat_p_<id> identities; with Full Page Cache in Varnish mode
 * Magento_CacheInvalidate broadcasts them as a PURGE carrying
 * X-Magento-Tags-Pattern to every configured cache host, and the headless
 * storefront's /api/revalidate endpoint already parses cat_p_<id> out of that
 * pattern and drops its cached related/up-sell/cross-sell rails. So the right
 * way to beat the storefront's own multi-hour cache is not to add anything - it
 * is to emit the identity Magento already knows how to broadcast.
 *
 * Preconditions, all outside this module: FPC in Varnish mode, a configured
 * http_cache_hosts, and an edge that forwards PURGE to the storefront. Without
 * them nothing is broadcast and new links appear only when the storefront cache
 * expires on its own.
 *
 * Deliberately does NOT invalidate any indexer. catalog_product_link feeds none
 * of them - the link types are read live by
 * Magento\Catalog\Model\ProductLink\CollectionProvider, and neither the search
 * nor the price indexer consumes link data. Invalidating on a bulk catalog
 * change is a reflex that would cost a full nightly reindex for nothing.
 */
class CacheInvalidator
{
    /**
     * @param CacheContext $cacheContext
     * @param EventManager $eventManager
     */
    public function __construct(
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * @param int[] $productIds
     * @return void
     */
    public function invalidateProducts(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return;
        }

        $this->cacheContext->registerEntities(Product::CACHE_TAG, $productIds);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
    }
}
