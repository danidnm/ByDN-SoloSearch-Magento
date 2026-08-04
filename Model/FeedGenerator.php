<?php

namespace Bydn\SoloSearch\Model;

use Bydn\SoloSearch\Model\Source\ConfigurablePriceMode;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;

class FeedGenerator
{
    // Feed fields always computed directly from the product (see buildItemNode()), regardless of
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

    /**
     * @var \Magento\Framework\Filesystem
     */
    private $filesystem;

    /**
     * @var \Magento\Framework\Module\Manager
     */
    private $moduleManager;

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
     * @var \Bydn\SoloSearch\Model\SoloSearchClient
     */
    private $soloSearchClient;

    /**
     * @var \Bydn\SoloSearch\Helper\Config
     */
    private $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Store view id for the current generate() call.
     *
     * @var int
     */
    private $storeId;

    /**
     * Currency code for the current generate() call.
     *
     * @var string
     */
    private $currencyCode;

    /**
     * Category id => name, built once per generate() call.
     *
     * @var array
     */
    private $categoryNames = [];

    /**
     * Feed field => attribute code, built once per generate() call.
     *
     * @var array
     */
    private $fieldMapping = [];

    /**
     * Name of the collection column holding the in-stock flag for the current generate() call:
     * "is_salable" under MSI, "is_in_stock" with the legacy single-stock inventory.
     *
     * @var string
     */
    private $stockColumnName = 'is_in_stock';

    /**
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\Module\Manager $moduleManager
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory
     * @param \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration
     * @param \Magento\Catalog\Model\Product\Visibility $productVisibility
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Bydn\SoloSearch\Helper\Config $config
     * @param \Bydn\SoloSearch\Model\SoloSearchClient $soloSearchClient
     */
    public function __construct(
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration,
        \Magento\Catalog\Model\Product\Visibility $productVisibility,
        \Bydn\SoloSearch\Model\SoloSearchClient $soloSearchClient,
        \Bydn\SoloSearch\Helper\Config $config,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->moduleManager = $moduleManager;
        $this->objectManager = $objectManager;
        $this->storeManager = $storeManager;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->stockConfiguration = $stockConfiguration;
        $this->productVisibility = $productVisibility;
        $this->soloSearchClient = $soloSearchClient;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Generates the feed for every store view, skipping those with the module disabled.
     *
     * Used by the manual solosearch:feed:generate command, which is expected to always run
     * regardless of the daily schedule - see generateForScheduledStores() for the cron path, which
     * additionally respects daily_generation_enabled.
     *
     * A failure generating one store's feed is caught and logged (see generateOneOfManyStores())
     * so it can't stop the rest of the batch from running - not used by the single-store CLI path
     * (solosearch:feed:generate --store=X calls generateForStoreIfEnabled() directly), which should
     * still surface a failure clearly instead of silently swallowing it.
     *
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function generateForAllStores()
    {
        foreach ($this->storeManager->getStores() as $store) {
            $this->generateOneOfManyStores((int) $store->getId());
        }
    }

    /**
     * Generates the feed for every store view scheduled for daily generation, skipping those with
     * either the module or the daily schedule disabled for that store view.
     *
     * Used by Cron\GenerateFeed only - the manual solosearch:feed:generate command intentionally
     * ignores daily_generation_enabled and always calls generateForAllStores() instead.
     *
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function generateForScheduledStores()
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();

            if (!$this->config->dailyGenerationEnabled($storeId)) {
                $this->logger->info(__METHOD__ . ": store {$storeId} daily generation disabled, skipping");

                continue;
            }

            $this->generateOneOfManyStores($storeId);
        }
    }

    /**
     * generateForStoreIfEnabled(), isolated so one store's failure (a malformed product, a
     * filesystem permission issue, whatever) can't stop generateForAllStores()/
     * generateForScheduledStores() from processing the rest of the stores in the same run.
     * Catches \Throwable, not just \Exception like the rest of this class - a PHP fatal turned
     * into a Throwable (e.g. a TypeError from unexpected product data) must be contained here too.
     *
     * @param int $storeId
     * @return void
     */
    private function generateOneOfManyStores($storeId)
    {
        try {
            $this->generateForStoreIfEnabled($storeId);
        } catch (\Throwable $e) {
            $this->logger->error(__METHOD__ . ": store {$storeId} failed, skipping to the next store - " . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Generates the feed for a single store view, or skips (and logs) it if disabled. Notifies
     * SoloSearch to reindex afterwards (best-effort - see SoloSearchClient::requestReindex()).
     *
     * Deliberately not called from generate() itself: SmokeTestCommand calls generate() directly,
     * many times per run, against scratch paths and SKU-restricted content - none of that is real
     * feed content SoloSearch should be told to go fetch, and it would burn through the reindex
     * endpoint's rate limit for no reason.
     *
     * @param int $storeId
     * @return void
     */
    public function generateForStoreIfEnabled($storeId)
    {
        if (!$this->config->isEnabled($storeId)) {
            $this->logger->info(__METHOD__ . ": store {$storeId} disabled, skipping");

            return;
        }

        $this->generate($storeId);
        $this->soloSearchClient->requestReindex($storeId);
    }

    /**
     * Generates the SoloSearch product feed for a single store view.
     *
     * $skus optionally restricts the feed to a specific set of SKUs instead of the whole catalog.
     * Not used in production (Cron/Console always generate the full feed) - it exists so
     * Console\Command\SmokeTestCommand can regenerate against a couple of known products instead
     * of the entire catalog, which is what makes the smoke test fast.
     *
     * @param int $storeId
     * @param array $skus
     * @return void
     */
    public function generate($storeId, array $skus = [])
    {
        $this->logger->info(__METHOD__ . ": start (store {$storeId})");

        $this->storeId = $storeId;
        $this->categoryNames = $this->loadCategoryNames();
        $this->fieldMapping = $this->config->getFieldMapping($storeId);

        $this->writeFeedFile($this->buildFeedXml($skus));

        $this->logger->info(__METHOD__ . ": end (store {$storeId})");
    }

    /**
     * Builds the XML feed for a store view - a plain custom format (no RSS/Google namespace: this
     * feed is only ever read by SoloSearch's own parser, not submitted anywhere else, so there is
     * no interoperability reason to dress it up as one).
     *
     * @param array $skus
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function buildFeedXml(array $skus = [])
    {
        $store = $this->storeManager->getStore($this->storeId);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $rss = $document->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $document->appendChild($rss);

        $channel = $document->createElement('channel');
        $rss->appendChild($channel);
        $this->appendNode($document, $channel, 'title', $store->getName(), true, false);
        $this->appendNode($document, $channel, 'link', $store->getBaseUrl(), true, false);

        $this->currencyCode = $store->getDefaultCurrencyCode();

        foreach ($this->getProductCollection($skus) as $product) {
            $channel->appendChild($this->buildItemNode($document, $product));
        }

        return $document->saveXML();
    }

    /**
     * Builds the product collection for a store view, applying every relevant SoloSearch config option
     *
     * @param array $skus
     * @return \Magento\Catalog\Model\ResourceModel\Product\Collection
     */
    private function getProductCollection(array $skus = [])
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($this->storeId);
        $collection->addStoreFilter($this->storeId);
        $collection->addUrlRewrite();
        $collection->addCategoryIds();

        if (!empty($skus)) {
            $collection->addFieldToFilter('sku', ['in' => $skus]);
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

        // The in-stock flag is always joined in (needed for the "availability" field in every item, see
        // buildItemNode()) and, additionally, used as a row filter unless Magento itself is
        // configured to show out-of-stock products storefront-wide (Catalog > Inventory >
        // Product Stock Options > Display Out of Stock Products) - otherwise the feed would link
        // to pages that are not reachable and 404. Deliberately just mirrors Magento's own
        // setting, no separate toggle of our own.
        $this->joinStockData($collection, !$this->stockConfiguration->isShowOutOfStock($this->storeId));

        $collection->addFieldToFilter('type_id', ['in' => $this->getAllowedTypeIds()]);

        return $collection;
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
     * already excluded by the visibility filter in getProductCollection(), so this list only
     * needs to decide whether the other types (configurable/bundle/virtual/downloadable/grouped)
     * are added on top, each via its own independent include_* toggle (all default to yes).
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
     * Builds the <item> node for a single product.
     *
     * The fields in STRUCTURAL_FEED_FIELDS (id, sku, title, link, image, price, sale_price,
     * availability, disable_add_to_cart, categories, currency) are always computed directly from
     * the product, never from field_mapping - a field_mapping row using one of these names is
     * ignored below.
     *
     * @param \DOMDocument $document
     * @param \Magento\Catalog\Model\Product $product
     * @return \DOMElement
     */
    private function buildItemNode(\DOMDocument $document, \Magento\Catalog\Model\Product $product)
    {
        $item = $document->createElement('item');
        
        // Configurable product price may come from the configurable itseft or from a child.
        // Otherwise, the product itself is the source of the price sent to search engine
        $priceSourceProduct = $this->resolvePriceSourceProduct($product);
        
        // Structural fields. Not configurable.
        $this->appendNode($document, $item, 'id', $product->getEntityId(), true, false);
        $this->appendNode($document, $item, 'sku', $product->getSku(), true, false);
        $this->appendNode($document, $item, 'title', $product->getName(), true, true);
        $this->appendNode($document, $item, 'link', $product->getProductUrl(), true, true);
        $this->appendNode($document, $item, 'image', $this->getProductAttributeValue($product, 'image'), true, true);
        $this->appendNode($document, $item, 'price', $this->getProductAttributeValue($priceSourceProduct, 'price'), true, true);
        $this->appendNode($document, $item, 'sale_price', $this->getProductAttributeValue($priceSourceProduct, 'special_price'), true, true);
        $this->appendNode($document, $item, 'availability', $this->getProductStockValue($product), true, false);
        $this->appendNode($document, $item, 'disable_add_to_cart', $this->hasDisabledAddToCart($product) ? '1' : '0', true, false);
        $this->appendNode($document, $item, 'categories', $this->getProductCategories($product), true, true);
        $this->appendNode($document, $item, 'currency', $this->currencyCode, true, true);

        // Mapped fields in admin
        foreach ($this->fieldMapping as $feedField => $attributeCode) {
            if ($attributeCode === '' || in_array($feedField, self::STRUCTURAL_FEED_FIELDS, true)) {
                continue;
            }

            $this->appendNode($document, $item, $feedField, $this->getProductAttributeValue($product, $attributeCode), true, true);
        }

        return $item;
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
            if (isset($this->categoryNames[$categoryId])) {
                $names[] = $this->categoryNames[$categoryId];
            }
        }

        return implode(' %% ', $names);
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

    /**
     * Writes the generated feed content to the configured (or default) relative path.
     *
     * Written to a uniquely-named temp file first and then moved into place with renameFile()
     * (atomic on the same filesystem, via the OS rename() syscall), so a consumer reading the feed
     * mid-write (e.g. SoloSearch fetching it over HTTP) never sees a partial/incomplete file. The
     * unique suffix also keeps two concurrent generations (cron + manual command) from writing to
     * the same temp file.
     *
     * @param string $xmlContent
     * @return void
     */
    private function writeFeedFile($xmlContent)
    {
        $relativePath = $this->config->getFeedPath($this->storeId);
        $tempPath = $relativePath . '.' . uniqid() . '.tmp';
        $rootDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $rootDirectory->create(dirname($relativePath));
        $rootDirectory->writeFile($tempPath, $xmlContent);
        $rootDirectory->renameFile($tempPath, $relativePath);
    }

    /**
     * Appends a child node to a parent node, skipping it entirely when the value is empty and $skipEmpty is true.
     *
     * @param \DOMDocument $document
     * @param \DOMElement $parent
     * @param string $name
     * @param string $value
     * @param bool $cdata
     * @param bool $skipEmpty
     * @return void
     */
    private function appendNode(\DOMDocument $document, \DOMElement $parent, $name, $value, $cdata = false, $skipEmpty = false)
    {
        if ($skipEmpty && $value === '') {
            return;
        }

        $node = $document->createElement($name);
        $node->appendChild($cdata ? $document->createCDATASection((string) $value) : $document->createTextNode((string) $value));

        $parent->appendChild($node);
    }
}
