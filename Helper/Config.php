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

    const XML_PATH_API_URL = 'solosearch/general/api_url';

    const XML_PATH_API_TOKEN = 'solosearch/general/api_token';

    const XML_PATH_WIDGET_ENABLE = 'solosearch/widget/enable';

    const XML_PATH_SEARCH_ENGINE_ID = 'solosearch/widget/search_engine_id';

    const XML_PATH_WIDGET_SCRIPT_URL = 'solosearch/widget/script_url';

    const XML_PATH_WIDGET_INPUT_SELECTOR = 'solosearch/widget/input_selector';

    const XML_PATH_WIDGET_LOCALE = 'solosearch/widget/locale';

    const XML_PATH_WIDGET_TEMPLATE_SET_ID = 'solosearch/widget/template_set_id';

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
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    private $encryptor;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Framework\Serialize\Serializer\Json $jsonSerializer
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Bydn\SoloSearch\Model\FeedPathValidator $feedPathValidator
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\Serialize\Serializer\Json $jsonSerializer,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Bydn\SoloSearch\Model\FeedPathValidator $feedPathValidator,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor
    ) {
        $this->jsonSerializer = $jsonSerializer;
        $this->storeManager = $storeManager;
        $this->feedPathValidator = $feedPathValidator;
        $this->encryptor = $encryptor;
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
     * Returns the base URL of SoloSearch's API (suite) - not suite-search's widget host
     * configured above (Widget Script URL is a different service). Used by SoloSearchClient for
     * all API requests. Defaults to the production API (see config.xml); a tenant would only ever
     * override this for local/staging testing against a different SoloSearch environment.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiUrl($storeId = null)
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_URL,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the UUID of the SearchEngine (in SoloSearch's panel) this store talks to.
     * Used both for the widget embed's data-engine attribute and to build the reindex
     * endpoint URL (/api/v1/search-engines/{uuid}/reindex).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSearchEngineId($storeId = null)
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SEARCH_ENGINE_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the decrypted SoloSearch API Token for this store. Stored encrypted
     * (type="obscure" + Magento\Config\Model\Config\Backend\Encrypted in system.xml),
     * so it must be decrypted here rather than read as-is.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiToken($storeId = null)
    {
        $encrypted = (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_TOKEN,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $encrypted === '' ? '' : $this->encryptor->decrypt($encrypted);
    }

    /**
     * Returns whether the widget script tag should be output on the storefront
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isWidgetEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_WIDGET_ENABLE,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the widget.js URL provided by SoloSearch
     *
     * @param int|null $storeId
     * @return string
     */
    public function getWidgetScriptUrl($storeId = null)
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WIDGET_SCRIPT_URL,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the CSS selector of this theme's own search input, or '' to let the widget
     * fall back to its own default (#search)
     *
     * @param int|null $storeId
     * @return string
     */
    public function getWidgetInputSelector($storeId = null)
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WIDGET_INPUT_SELECTOR,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the locale override for the widget, or '' to let it use the engine's own
     * configured locale in SoloSearch's panel
     *
     * @param int|null $storeId
     * @return string
     */
    public function getWidgetLocale($storeId = null)
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WIDGET_LOCALE,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the UUID of a SearchEngineTemplateSet to force via data-template, or '' to let
     * the widget use the engine's current default template
     *
     * @param int|null $storeId
     * @return string
     */
    public function getWidgetTemplateSetId($storeId = null)
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WIDGET_TEMPLATE_SET_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
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
