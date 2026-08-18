<?php

namespace Bydn\SoloSearch\Api;

/**
 * Public entry point for telling SoloSearch a product changed or was deleted, so it can be synced
 * to the search index immediately instead of waiting for the next scheduled Feed Reindex. Meant
 * to be used both by this module's own observers and by third-party code (a custom importer that
 * only ever touches loose attributes, for example) - depend on this interface, not on
 * Model\ProductChangeNotifier directly.
 */
interface ProductChangeNotifierInterface
{
    /**
     * Queues a product for a single-product update via SoloSearch's API.
     *
     * No-ops entirely if SoloSearch is disabled for this store - callers don't need to check that
     * themselves first.
     *
     * $changedAttributeCodes lets the caller narrow this down to only the attributes it knows
     * changed:
     *   - null (default): the caller doesn't track which attributes changed - the product is
     *     always queued. Safe default for a caller that always writes full products.
     *   - array (possibly empty): only the attribute codes listed are considered. The product is
     *     queued only if at least one of them is actually sent to SoloSearch (see
     *     getWatchedAttributeCodes()) - e.g. a change to `short_description` alone is ignored if
     *     that attribute isn't part of the feed. This check always happens here, regardless of
     *     whether the caller already pre-filtered against getWatchedAttributeCodes() itself.
     *
     * @param int $productId
     * @param int $storeId
     * @param string[]|null $changedAttributeCodes
     * @return void
     */
    public function productChanged($productId, $storeId, array $changedAttributeCodes = null);

    /**
     * Queues a product for deletion from the SoloSearch index. No-ops if SoloSearch is disabled
     * for this store.
     *
     * @param int $productId
     * @param int $storeId
     * @return void
     */
    public function productDeleted($productId, $storeId);

    /**
     * Every attribute code whose value ends up in the SoloSearch feed for this store: the
     * structural fields (see FeedGenerator::STRUCTURAL_ATTRIBUTE_CODES and
     * Model\ProductChangeNotifier::EXTRA_WATCHED_ATTRIBUTE_CODES) plus whatever is currently
     * configured in Field Mapping.
     *
     * Deliberately independent of whether SoloSearch is enabled for this store - this is purely
     * "what would be relevant if it were syncing", not a policy decision. Callers that need to act
     * differently when the store is disabled (productChanged()/productDeleted() do, internally)
     * check that themselves.
     *
     * @param int $storeId
     * @return string[]
     */
    public function getWatchedAttributeCodes($storeId);
}
