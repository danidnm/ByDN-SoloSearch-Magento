<?php

namespace Bydn\SoloSearch\Model;

use Bydn\SoloSearch\Model\Source\ConfigurablePriceMode;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;

/**
 * Everything about turning raw Magento product data into SoloSearch fields - agnostic to who uses
 * it or what they do with the result. Always returns a plain array, never XML/JSON/anything
 * output-format-specific - that's each caller's own job (FeedGenerator turns it into the feed's
 * XML; Model\ProductQueueSync sends it as JSON to the single-product API).
 *
 * Shared by FeedGenerator (the whole-catalogue Feed Reindex) and ProductQueueSync (the real-time
 * single-product sync) so both can never disagree on what a product's fields are - neither depends
 * on the other, both depend on this instead.
 */
class ProductFieldsBuilder
{
    // Feed fields always computed directly from the product (see buildFields()), regardless of
    // field_mapping - any field_mapping row using one of these names is ignored, since the
    // structural value always wins.
    const STRUCTURAL_FEED_FIELDS = [
        'id', 'sku', 'title', 'link', 'image', 'price', 'sale_price', 'availability', 'disable_add_to_cart', 'categories', 'currency',
    ];

    // Attribute codes needed to build the structural fields above, always selected on the product
    // collection regardless of what field_mapping references. required_options is a native Magento
    // system attribute (kept in sync by core whenever custom options are saved) - see
    // hasDisabledAddToCart() for why it's read directly instead of loading the options collection.
    const STRUCTURAL_ATTRIBUTE_CODES = ['name', 'image', 'price', 'special_price', 'required_options'];

    // Attribute codes that hold a media image path and therefore need to be resolved to a full URL.
    const IMAGE_ATTRIBUTE_CODES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    // getCollection() bulk-loads every category name up front for the whole store (like a full
    // Feed Reindex always did) whenever it isn't restricted to a small, explicit set of product
    // ids - a SKU-restricted or unrestricted collection is likely to touch most of the store's
    // categories anyway. Below this size, categories are instead resolved one at a time as they're
    // actually referenced (see getCategoryName()), to avoid loading potentially thousands of
    // unrelated category names for a handful of products. Not based on any measurement, just a
    // reasonable-sounding round number - revisit if it turns out wrong.
    const CATEGORY_BULK_LOAD_THRESHOLD = 50;

    /**
     * Used only to lazily resolve MSI services (StockResolverInterface, AddStockDataToCollection)
     * when MSI modules are actually enabled - see isMsiActive(). Those modules ship with Magento
     * but can be disabled, and constructor-injecting their interfaces directly would make the
     * whole object graph (and therefore every bin/magento command, since the command list is
     * built eagerly) fail to resolve when they are off.
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\Module\Manager
     */
    private $moduleManager;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var \Magento\CatalogInventory\Api\StockConfigurationInterface
     */
    private $stockConfiguration;

    /**
     * @var \Magento\Catalog\Model\Product\Visibility
     */
    private $productVisibility;

    /**
     * @var \Bydn\SoloSearch\Helper\Config
     */
    private $config;

    /**
     * Store view id for the current getCollection() call.
     *
     * @var int
     */
    private $storeId;

    /**
     * Currency code for the current getCollection() call.
     *
     * @var string
     */
    private $currencyCode;

    /**
     * Category id => name (or null for "looked up, doesn't exist/doesn't qualify"). Reset at the
     * start of every getCollection() call - see its docblock for why this can't be left to persist
     * across calls for different stores.
     *
     * @var array
     */
    private $categoryNames = [];

    /**
     * Feed field => attribute code, built once per getCollection() call.
     *
     * @var array
     */
    private $fieldMapping = [];

    /**
     * Name of the collection column holding the in-stock flag for the current getCollection()
     * call: "is_salable" under MSI, "is_in_stock" with the legacy single-stock inventory.
     *
     * @var string
     */
    private $stockColumnName = 'is_in_stock';

    /**
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param \Magento\Framework\Module\Manager $moduleManager
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory
     * @param \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration
     * @param \Magento\Catalog\Model\Product\Visibility $productVisibility
     * @param \Bydn\SoloSearch\Helper\Config $config
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration,
        \Magento\Catalog\Model\Product\Visibility $productVisibility,
        \Bydn\SoloSearch\Helper\Config $config
    ) {
        $this->objectManager = $objectManager;
        $this->moduleManager = $moduleManager;
        $this->storeManager = $storeManager;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->stockConfiguration = $stockConfiguration;
        $this->productVisibility = $productVisibility;
        $this->config = $config;
    }

    /**
     * Builds the product collection for a store view, applying every relevant SoloSearch config
     * option - the single entry point that primes every bit of per-store state buildFields() then
     * reads (store id, field mapping, currency, category names, which stock column got joined).
     * Callers must always go through here before calling buildFields(), for any store.
     *
     * $skus and $productIds are alternative, independent ways to restrict which products come
     * back - $skus for FeedGenerator's targeted testing tools, $productIds for
     * buildFieldsForProducts()/the real-time product sync. Neither is required; with both empty
     * the collection covers the store's whole catalogue.
     *
     * @param int $storeId
     * @param array $skus
     * @param array $productIds
     * @return \Magento\Catalog\Model\ResourceModel\Product\Collection
     */
    public function getCollection($storeId, array $skus = [], array $productIds = [])
    {
        $this->storeId = $storeId;
        $this->fieldMapping = $this->config->getFieldMapping($storeId);
        $this->currencyCode = $this->storeManager->getStore($storeId)->getDefaultCurrencyCode();

        // Category names are per-store, and this object may be reused across multiple stores
        // within the same run (see ProductQueueSync::syncAllStores()) - always reset here rather
        // than only bulk-reloading for the "large batch" case below, or a small batch for store B
        // could silently keep resolving category ids against store A's names.
        $bulkLoadCategories = empty($productIds) || count($productIds) >= self::CATEGORY_BULK_LOAD_THRESHOLD;
        $this->categoryNames = $bulkLoadCategories ? $this->loadCategoryNames() : [];

        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addUrlRewrite();
        $collection->addCategoryIds();

        if (!empty($skus)) {
            $collection->addFieldToFilter('sku', ['in' => $skus]);
        }

        if (!empty($productIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
        }

        $attributeCodes = array_unique(array_merge(
            self::STRUCTURAL_ATTRIBUTE_CODES,
            array_filter(array_values($this->fieldMapping))
        ));
        $collection->addAttributeToSelect(array_values($attributeCodes));

        // Disabled products always 404 on the storefront regardless of any config, so there is no
        // scenario where including them would make sense - not configurable.
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);

        // "Not Visible Individually" and "Catalog"-only products are not meant to be found on
        // their own - SoloSearch is a search feed, so only products visible in search make sense.
        // Not configurable, same reasoning as status above.
        $collection->addAttributeToFilter('visibility', ['in' => $this->productVisibility->getVisibleInSearchIds()]);

        // The in-stock flag is always joined in (needed for the "availability" field in every item,
        // see buildFields()) and, additionally, used as a row filter unless Magento itself is
        // configured to show out-of-stock products storefront-wide (Catalog > Inventory >
        // Product Stock Options > Display Out of Stock Products) - otherwise the feed would link
        // to pages that are not reachable and 404. Deliberately just mirrors Magento's own
        // setting, no separate toggle of our own.
        $this->joinStockData($collection, !$this->stockConfiguration->isShowOutOfStock($storeId));

        $collection->addFieldToFilter('type_id', ['in' => $this->getAllowedTypeIds()]);

        return $collection;
    }

    /**
     * Computes every SoloSearch field for a single product. The fields in STRUCTURAL_FEED_FIELDS
     * (id, sku, title, link, image, price, sale_price, availability, disable_add_to_cart,
     * categories, currency) are always computed directly from the product, never from
     * field_mapping - a field_mapping row using one of these names is ignored below.
     *
     * $product must come from a getCollection() call for the same store, made earlier in the same
     * request - this method reads the per-store state (field mapping, currency, category names,
     * stock column) that call primed, it doesn't prime any of it itself.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return array<string, mixed>
     */
    public function buildFields(\Magento\Catalog\Model\Product $product)
    {
        // Configurable product price may come from the configurable itself or from a child.
        // Otherwise, the product itself is the source of the price sent to search engine
        $priceSourceProduct = $this->resolvePriceSourceProduct($product);

        $fields = [
            'id' => $product->getEntityId(),
            'sku' => $product->getSku(),
            'title' => $product->getName(),
            'link' => $product->getProductUrl(),
            'image' => $this->getProductAttributeValue($product, 'image'),
            'price' => $this->getProductAttributeValue($priceSourceProduct, 'price'),
            'sale_price' => $this->getProductAttributeValue($priceSourceProduct, 'special_price'),
            'availability' => $this->getProductStockValue($product),
            'disable_add_to_cart' => $this->hasDisabledAddToCart($product) ? '1' : '0',
            'categories' => $this->getProductCategories($product),
            'currency' => $this->currencyCode,
        ];

        // A special_price attribute that isn't actually lower than price (equal, or - shouldn't
        // normally happen, but defend anyway - higher) is not a real discount, so no <sale_price>
        // node should be sent at all. Bundles already get this treatment inside
        // getBundlePriceValue() (their "special_price" is a % off, not a plain value comparison);
        // this covers every other product type, where getProductAttributeValue() just returns the
        // raw attribute regardless of whether it's still meaningfully lower than price.
        if ($fields['sale_price'] !== '' && (float) $fields['sale_price'] >= (float) $fields['price']) {
            $fields['sale_price'] = '';
        }

        // Mapped fields in admin
        foreach ($this->fieldMapping as $feedField => $attributeCode) {
            if ($attributeCode === '' || in_array($feedField, self::STRUCTURAL_FEED_FIELDS, true)) {
                continue;
            }

            $fields[$feedField] = $this->getProductAttributeValue($product, $attributeCode);
        }

        return $fields;
    }

    /**
     * Computes SoloSearch fields for a specific set of products - used by the real-time product
     * sync (Model\ProductQueueSync) instead of waiting for the next scheduled Feed Reindex. Goes
     * through the exact same collection (stock joined, field mapping applied, status/visibility/
     * type filters) a full Feed Reindex uses for the whole catalogue, so a synced product can
     * never disagree in shape or values with what a full reindex would have produced for it.
     *
     * A product id that doesn't come back in the result means it's currently excluded by the
     * collection's own filters (disabled, not visible in search, disallowed type...) - callers
     * must treat a missing id as "remove this product from the index", not as an error.
     *
     * @param int $storeId
     * @param int[] $productIds
     * @return array<int, array<string, mixed>> SoloSearch fields keyed by product id
     */
    public function buildFieldsForProducts($storeId, array $productIds)
    {
        if (empty($productIds)) {
            return [];
        }

        $result = [];

        foreach ($this->getCollection($storeId, [], $productIds) as $product) {
            $result[(int) $product->getId()] = $this->buildFields($product);
        }

        return $result;
    }

    /**
     * Whether a single product currently qualifies for the feed on status/visibility grounds -
     * the same status/visibility criteria getCollection() applies as SQL filters above, evaluated
     * in PHP against an already-loaded product instead. Used outside a full feed generation
     * (ProductChangeNotifier/observers) to tell whether a status or visibility change should
     * result in the product being updated in the index or removed from it entirely.
     *
     * Only covers status/visibility, not every other inclusion rule getCollection() applies
     * (product type, configurable/bundle/virtual/downloadable/grouped toggles) - those don't change
     * on an existing product's save the way status/visibility routinely do.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return bool
     */
    public function isVisibleInFeed(\Magento\Catalog\Model\Product $product)
    {
        return (int) $product->getStatus() === (int) Status::STATUS_ENABLED
            && in_array((int) $product->getVisibility(), $this->productVisibility->getVisibleInSearchIds(), true);
    }

    /**
     * Joins in-stock data into the product collection, resolving it per-store via MSI (multiple
     * stocks can exist, each store view can be assigned a different one, so stock can genuinely
     * differ between the feeds of two store views) when available, falling back to the legacy
     * single-stock inventory otherwise. Either way, the joined column is exposed as
     * $this->stockColumnName so the rest of the class doesn't need to care which path was taken.
     *
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
     * @param bool $filterInStock
     * @return void
     */
    private function joinStockData($collection, $filterInStock)
    {
        if ($this->isMsiActive()) {
            $stockId = $this->resolveStockId();
            $addStockDataToCollection = $this->objectManager->create(
                \Magento\InventoryCatalog\Model\ResourceModel\AddStockDataToCollection::class
            );
            $addStockDataToCollection->execute($collection, $filterInStock, $stockId);
            $this->stockColumnName = 'is_salable';

            return;
        }

        $collection->joinField(
            'is_in_stock',
            'cataloginventory_stock_item',
            'is_in_stock',
            'product_id=entity_id',
            '{{table}}.stock_id=1',
            $filterInStock ? 'inner' : 'left'
        );

        if ($filterInStock) {
            $collection->addFieldToFilter('is_in_stock', 1);
        }

        $this->stockColumnName = 'is_in_stock';
    }

    /**
     * Resolves the stock id assigned to a store's website (MSI allows different stocks - and
     * therefore different stock levels - per website/store view).
     *
     * @return int
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function resolveStockId()
    {
        $websiteId = $this->storeManager->getStore($this->storeId)->getWebsiteId();
        $websiteCode = $this->storeManager->getWebsite($websiteId)->getCode();

        $stockResolver = $this->objectManager->get(\Magento\InventorySalesApi\Api\StockResolverInterface::class);

        return (int) $stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode)->getStockId();
    }

    /**
     * @return bool
     */
    private function isMsiActive()
    {
        return $this->moduleManager->isEnabled('Magento_InventorySalesApi')
            && $this->moduleManager->isEnabled('Magento_InventoryCatalogApi')
            && $this->moduleManager->isEnabled('Magento_InventoryCatalog');
    }

    /**
     * Builds the list of product type_id values allowed in the feed. Simple products are always
     * included - a simple that is a configurable's child but shouldn't be found on its own is
     * already excluded by the visibility filter in getCollection(), so this list only needs to
     * decide whether the other types (configurable/bundle/virtual/downloadable/grouped) are added
     * on top, each via its own independent include_* toggle (all default to yes).
     *
     * @return array
     */
    private function getAllowedTypeIds()
    {
        $types = ['simple'];

        if ($this->config->includeConfigurableProducts($this->storeId)) {
            $types[] = 'configurable';
        }
        if ($this->config->includeBundleProducts($this->storeId)) {
            $types[] = 'bundle';
        }
        if ($this->config->includeVirtualProducts($this->storeId)) {
            $types[] = 'virtual';
        }
        if ($this->config->includeDownloadableProducts($this->storeId)) {
            $types[] = 'downloadable';
        }
        if ($this->config->includeGroupedProducts($this->storeId)) {
            $types[] = 'grouped';
        }

        return $types;
    }

    /**
     * For configurable products, returns the child variant whose price should be used for
     * price/sale_price instead of the configurable's own price attribute (configurables don't have
     * a meaningful price of their own in Magento - it's typically 0/empty). Every other field still
     * comes from the configurable itself (title, image...), only price/sale_price are affected.
     *
     * CHEAPEST_CHILD considers only enabled children, falling back to any child (including
     * disabled) when the configurable has no enabled child at all - see findCheapestChild().
     * CHEAPEST_CHILD_ANY always considers every child regardless of status.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return \Magento\Catalog\Model\Product
     */
    private function resolvePriceSourceProduct(\Magento\Catalog\Model\Product $product)
    {
        if ($product->getTypeId() !== 'configurable') {
            return $product;
        }

        $priceMode = $this->config->getConfigurablePriceMode($this->storeId);

        if ($priceMode !== ConfigurablePriceMode::CHEAPEST_CHILD
            && $priceMode !== ConfigurablePriceMode::CHEAPEST_CHILD_ANY
        ) {
            return $product;
        }

        $cheapestChild = $this->findCheapestChild($product, $priceMode === ConfigurablePriceMode::CHEAPEST_CHILD);

        return $cheapestChild ?: $product;
    }

    /**
     * Finds the child variant with the lowest final_price among a configurable product's children.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param bool $onlyEnabled If true and no enabled child exists, falls back to considering every
     *     child (including disabled) instead of returning null.
     * @return \Magento\Catalog\Model\Product|null
     */
    private function findCheapestChild(\Magento\Catalog\Model\Product $product, $onlyEnabled)
    {
        $children = $product->getTypeInstance()->getUsedProducts($product);

        $cheapest = $this->findCheapestAmong($children, $onlyEnabled);

        if ($cheapest === null && $onlyEnabled) {
            $cheapest = $this->findCheapestAmong($children, false);
        }

        return $cheapest;
    }

    /**
     * @param \Magento\Catalog\Model\Product[] $children
     * @param bool $onlyEnabled
     * @return \Magento\Catalog\Model\Product|null
     */
    private function findCheapestAmong(array $children, $onlyEnabled)
    {
        $cheapest = null;
        $cheapestPrice = null;

        foreach ($children as $child) {
            if ($onlyEnabled && (int) $child->getStatus() !== Status::STATUS_ENABLED) {
                continue;
            }

            $finalPrice = (float) $child->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();

            if ($cheapestPrice === null || $finalPrice < $cheapestPrice) {
                $cheapestPrice = $finalPrice;
                $cheapest = $child;
            }
        }

        return $cheapest;
    }

    /**
     * Returns "in_stock" or "out_of_stock" for a product, using whichever column joinStockData()
     * exposed (MSI or legacy).
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    private function getProductStockValue(\Magento\Catalog\Model\Product $product)
    {
        return $product->getData($this->stockColumnName) ? 'in_stock' : 'out_of_stock';
    }

    /**
     * Whether the product can NOT be added to cart with a plain product_id+qty=1 request and
     * therefore needs its own product page instead (used by SoloSearch's widget to decide whether
     * to show an add-to-cart button on a search result card):
     * - configurable: always, a variant (super_attribute) must be picked first.
     * - grouped: always. Magento's own hasRequiredOptions() reports false for these (there is no
     *   "required option" to pick), but they still can't be added this way - they need
     *   super_group[childId]=qty per child product instead, not a single product id.
     * - bundle: always. In theory a bundle with zero required options could accept a plain add
     *   (unspecified optional selections are just skipped by Magento's bundle type, not an error),
     *   but that's a rare, unverified edge case - not worth risking a silently broken/empty cart
     *   line over, so bundle is treated the same as configurable/grouped regardless of its options.
     * - required_options: native Magento product attribute, kept in sync by core whenever the
     *   product's own "Customizable Options" include at least one required one. Read directly
     *   instead of iterating $product->getOptions(), since that collection isn't reliably loaded
     *   on a product coming from a plain collection (see STRUCTURAL_ATTRIBUTE_CODES).
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return bool
     */
    private function hasDisabledAddToCart(\Magento\Catalog\Model\Product $product)
    {
        $typeId = $product->getTypeId();

        if ($typeId === 'configurable' || $typeId === 'grouped' || $typeId === 'bundle') {
            return true;
        }

        return (bool) $product->getData('required_options');
    }

    /**
     * Resolves the value of a mapped product attribute, turning image attributes into full URLs
     * and select/multiselect attributes into their label(s) instead of the raw option id(s).
     *
     * Bundle products are a special case for "price"/"special_price" - see getBundlePriceValue().
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string $attributeCode
     * @return string
     */
    private function getProductAttributeValue(\Magento\Catalog\Model\Product $product, $attributeCode)
    {
        if (($attributeCode === 'price' || $attributeCode === 'special_price') && $product->getTypeId() === 'bundle') {
            return $this->getBundlePriceValue($product, $attributeCode);
        }

        if (in_array($attributeCode, self::IMAGE_ATTRIBUTE_CODES, true)) {
            $imagePath = $product->getData($attributeCode);

            return $imagePath ? $product->getMediaConfig()->getMediaUrl($imagePath) : '';
        }

        $value = $product->getAttributeText($attributeCode);

        if ($value === false) {
            $value = $product->getData($attributeCode);
        }

        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }

        return trim((string) $value);
    }

    /**
     * Resolves "price" (regular) or "special_price" (sale) for a bundle product via the pricing
     * framework instead of reading the raw attributes directly:
     * - "price": the bundle's own price attribute is only meaningful with fixed pricing; with
     *   dynamic pricing it's 0/null, so regular_price is used instead - the bundle's "as low as"
     *   price with no discount applied, correct for both pricing modes.
     * - "special_price": for bundles this attribute is a discount PERCENTAGE (0-100), not a
     *   currency amount (see Magento\Bundle\Pricing\Price\SpecialPrice) - exporting it raw would
     *   produce e.g. "20" as if it were a price. final_price already has that percentage applied,
     *   so it's exported here only when actually lower than regular_price (an active discount) -
     *   otherwise this returns '' so no <sale_price> node is added, same as a simple product with
     *   no special price set.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string $attributeCode
     * @return string
     */
    private function getBundlePriceValue(\Magento\Catalog\Model\Product $product, $attributeCode)
    {
        $regularPrice = (float) $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();

        if ($attributeCode === 'price') {
            return (string) round($regularPrice, 2);
        }

        $finalPrice = (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();

        if ($finalPrice >= $regularPrice - 0.001) {
            return '';
        }

        return (string) round($finalPrice, 2);
    }

    /**
     * Returns the product's category names, joined the same way suite's feed parser expects: " %% " separated.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    private function getProductCategories(\Magento\Catalog\Model\Product $product)
    {
        $names = [];

        foreach ($product->getCategoryIds() as $categoryId) {
            $name = $this->getCategoryName($categoryId);

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return implode(' %% ', $names);
    }

    /**
     * Resolves a single category's name, through the same $categoryNames cache loadCategoryNames()
     * bulk-fills. If the id isn't cached yet - the normal case when getCollection() skipped the
     * bulk load for a small product-id-restricted batch - it's looked up on its own and the result
     * cached (including a "not found" result, as null) so the same id is never queried twice
     * within one run.
     *
     * Applies the same only_navigable_categories filter loadCategoryNames() does, so a category
     * excluded by that setting resolves to null here exactly like it would be absent from the bulk
     * load - never present in one path and missing in the other.
     *
     * @param int $categoryId
     * @return string|null
     */
    private function getCategoryName($categoryId)
    {
        if (array_key_exists($categoryId, $this->categoryNames)) {
            return $this->categoryNames[$categoryId];
        }

        $categories = $this->categoryCollectionFactory->create();
        $categories->setStoreId($this->storeId);
        $categories->addAttributeToSelect('name');
        $categories->addFieldToFilter('entity_id', $categoryId);

        if ($this->config->onlyNavigableCategories($this->storeId)) {
            $categories->addAttributeToFilter('include_in_menu', 1);
        }

        $name = null;

        foreach ($categories as $category) {
            $name = $category->getName();
        }

        $this->categoryNames[$categoryId] = $name;

        return $name;
    }

    /**
     * Loads every category name for a store view in a single query. When only_navigable_categories
     * is enabled, the collection itself is filtered to categories shown in the navigation menu, so
     * getProductCategories() naturally excludes the rest for every product without any extra
     * per-row filtering.
     *
     * @return array
     */
    private function loadCategoryNames()
    {
        $categories = $this->categoryCollectionFactory->create();
        $categories->setStoreId($this->storeId);
        $categories->addAttributeToSelect('name');

        if ($this->config->onlyNavigableCategories($this->storeId)) {
            $categories->addAttributeToFilter('include_in_menu', 1);
        }

        $names = [];

        foreach ($categories as $category) {
            $names[$category->getId()] = $category->getName();
        }

        return $names;
    }
}
