<?php

namespace Bydn\SoloSearch\Helper;

class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    const XML_PATH_ENABLE = 'solosearch/general/enable';

    const XML_PATH_INCLUDE_CONFIGURABLE_PRODUCTS = 'solosearch/general/include_configurable_products';

    const XML_PATH_CONFIGURABLE_PRICE_MODE = 'solosearch/general/configurable_price_mode';

    const XML_PATH_ONLY_NAVIGABLE_CATEGORIES = 'solosearch/general/only_navigable_categories';

    const XML_PATH_INCLUDE_BUNDLE_PRODUCTS = 'solosearch/general/include_bundle_products';

    const XML_PATH_INCLUDE_VIRTUAL_PRODUCTS = 'solosearch/general/include_virtual_products';

    const XML_PATH_INCLUDE_DOWNLOADABLE_PRODUCTS = 'solosearch/general/include_downloadable_products';

    const XML_PATH_INCLUDE_GROUPED_PRODUCTS = 'solosearch/general/include_grouped_products';

    const XML_PATH_FEED_PATH = 'solosearch/feed_generation/feed_path';

    const XML_PATH_DAILY_GENERATION_ENABLED = 'solosearch/feed_generation/daily_generation_enabled';

    const XML_PATH_FIELD_MAPPING = 'solosearch/field_mapping/mapping';

    // Used when general/feed_path is not configured for the store view. Under pub/ (Magento's
    // public docroot), not the bare media/ at the Magento root, so the default is downloadable
    // over HTTP without any extra web server configuration.
    const DEFAULT_FEED_PATH_TEMPLATE = 'pub/media/feeds/solosearch/%s/solosearch.xml';

    // Safety net used when the field_mapping/mapping config value cannot be read or is empty.
    // Kept in sync with config.xml's default - id/title/image/price/sale_price/availability/
    // categories/currency are NOT listed here, since those are structural fields computed
    // directly by FeedGenerator regardless of field_mapping (see STRUCTURAL_FEED_FIELDS).
    const DEFAULT_FIELD_MAPPING = [
        'brand' => 'manufacturer',
        'description' => 'description',
        'short_description' => 'short_description',
    ];

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $jsonSerializer;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Bydn\SoloSearch\Model\FeedPathValidator
     */
    private $feedPathValidator;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Framework\Serialize\Serializer\Json $jsonSerializer
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Bydn\SoloSearch\Model\FeedPathValidator $feedPathValidator
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\Serialize\Serializer\Json $jsonSerializer,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Bydn\SoloSearch\Model\FeedPathValidator $feedPathValidator
    ) {
        $this->jsonSerializer = $jsonSerializer;
        $this->storeManager = $storeManager;
        $this->feedPathValidator = $feedPathValidator;
        parent::__construct($context);
    }

    /**
     * Returns whether the SoloSearch feed generation is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns whether configurable products should be included in the feed. Simple products are
     * always included regardless of this setting - whether a simple that happens to be a
     * configurable's child should be found on its own is governed by its own Magento visibility
     * setting (Not Visible Individually / Catalog / Search), not by this toggle.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function includeConfigurableProducts($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_INCLUDE_CONFIGURABLE_PRODUCTS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the price resolution strategy for configurable products: the configurable's own
     * price attribute, or the cheapest enabled child's price.
     * See Bydn\SoloSearch\Model\Source\ConfigurablePriceMode for the possible values.
     *
     * @param int|null $storeId
     * @return mixed
     */
    public function getConfigurablePriceMode($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_CONFIGURABLE_PRICE_MODE,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns whether only categories present in navigation menus should be exported for a product
     *
     * @param int|null $storeId
     * @return bool
     */
    public function onlyNavigableCategories($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ONLY_NAVIGABLE_CATEGORIES,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns whether bundle products should be included in the feed
     *
     * @param int|null $storeId
     * @return bool
     */
    public function includeBundleProducts($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_INCLUDE_BUNDLE_PRODUCTS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns whether virtual products should be included in the feed
     *
     * @param int|null $storeId
     * @return bool
     */
    public function includeVirtualProducts($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_INCLUDE_VIRTUAL_PRODUCTS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns whether downloadable products should be included in the feed
     *
     * @param int|null $storeId
     * @return bool
     */
    public function includeDownloadableProducts($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_INCLUDE_DOWNLOADABLE_PRODUCTS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns whether grouped products should be included in the feed
     *
     * @param int|null $storeId
     * @return bool
     */
    public function includeGroupedProducts($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_INCLUDE_GROUPED_PRODUCTS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns whether the scheduled daily generation job should run for this store view.
     * Checked by Cron\GenerateFeed - the manual solosearch:feed:generate command ignores it.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function dailyGenerationEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_DAILY_GENERATION_ENABLED,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the relative path of the generated feed file for a store view.
     * Falls back to pub/media/feeds/solosearch/{store_code}/solosearch.xml when not configured, or
     * when configured with an absolute path or a ".." traversal segment (see FeedPathValidator) -
     * the backend model on the field already rejects those on save, this is a defensive fallback
     * for values that reach this point some other way (e.g. a different config scope, direct DB edit).
     *
     * @param int|null $storeId
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getFeedPath($storeId = null)
    {
        $configuredPath = $this->scopeConfig->getValue(
            self::XML_PATH_FEED_PATH,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!empty($configuredPath) && $this->feedPathValidator->isValid($configuredPath)) {
            return $configuredPath;
        }

        $storeCode = $this->storeManager->getStore($storeId)->getCode();

        return sprintf(self::DEFAULT_FEED_PATH_TEMPLATE, $storeCode);
    }

    /**
     * Returns the mapping between SoloSearch feed fields and Magento product attribute codes,
     * as configured in the dynamic "Field Mapping" grid (Bydn\SoloSearch\Block\Adminhtml\Form\Field\FieldMapping).
     *
     * @param int|null $storeId
     * @return array
     */
    public function getFieldMapping($storeId = null)
    {
        $rawValue = $this->scopeConfig->getValue(
            self::XML_PATH_FIELD_MAPPING,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $rows = $this->decodeFieldMappingRows($rawValue);

        if (empty($rows)) {
            return self::DEFAULT_FIELD_MAPPING;
        }

        $mapping = [];

        foreach ($rows as $row) {
            if (!empty($row['feed_field'])) {
                $mapping[$row['feed_field']] = $row['attribute_code'] ?? '';
            }
        }

        return $mapping;
    }

    /**
     * Decodes the raw field_mapping/mapping config value into a plain list of rows.
     * The value is a native array when it comes from an XML default (config.xml), or a JSON
     * string once it has been saved through the admin grid (Serialized backend model).
     *
     * @param mixed $rawValue
     * @return array
     */
    private function decodeFieldMappingRows($rawValue)
    {
        if (is_array($rawValue)) {
            return $rawValue;
        }

        if (empty($rawValue)) {
            return [];
        }

        try {
            return $this->jsonSerializer->unserialize($rawValue);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
    }
}
