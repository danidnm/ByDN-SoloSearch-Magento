<?php

namespace Bydn\SoloSearch\Console\Command;

use Bydn\SoloSearch\Helper\Config;
use Bydn\SoloSearch\Model\Config\Backend\GenerationTime;
use Bydn\SoloSearch\Model\FeedGenerator;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriterInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Smoke test for the SoloSearch feed generator: runs the real FeedGenerator against the current
 * (development) catalog, toggling one config option at a time to verify it actually changes the
 * generated feed as expected. Not a PHPUnit test - it runs against the real dev database, so every
 * config change it makes is captured and restored (see withConfig()). Regenerated feed content is
 * written to a scratch path under var/, except for the one check that intentionally verifies the
 * real configured (or default) feed path.
 */
class SmokeTestCommand extends \Symfony\Component\Console\Command\Command
{
    const PARAM_STORE = 'store';

    const PARAM_FORCE = 'force';

    const TEMP_DIR = 'var/solosearch-smoke-test';

    // Durable record of every config value changed mid-run, in case the process dies before it
    // gets to restore them (killed, OOM, crash). See rememberOriginal()/forgetOriginal().
    const CONFIG_BACKUP_FILE = 'var/solosearch-smoke-test/config-backup.json';

    /**
     * @var \Magento\Framework\App\State
     */
    private $appState;

    /**
     * @var ConfigWriterInterface
     */
    private $configWriter;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ReinitableConfigInterface
     */
    private $reinitableConfig;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Json
     */
    private $jsonSerializer;

    /**
     * Used only to get a fresh (non-shared) GenerationTime backend model instance for the
     * generation_time scenario - acceptable here since this is a diagnostic/tooling command,
     * not business logic.
     *
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * Used to read the raw core_config_data row for a path/scope, bypassing the XML-default
     * fallback that ScopeConfigInterface::getValue() applies - see withConfig().
     *
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ProductCollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var Visibility
     */
    private $productVisibility;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var FeedGenerator
     */
    private $feedGenerator;

    /**
     * @param \Magento\Framework\App\State $appState
     * @param ConfigWriterInterface $configWriter
     * @param ScopeConfigInterface $scopeConfig
     * @param ReinitableConfigInterface $reinitableConfig
     * @param Filesystem $filesystem
     * @param Json $jsonSerializer
     * @param ObjectManagerInterface $objectManager
     * @param ResourceConnection $resourceConnection
     * @param StoreManagerInterface $storeManager
     * @param ProductCollectionFactory $productCollectionFactory
     * @param Visibility $productVisibility
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory
     * @param Config $config
     * @param FeedGenerator $feedGenerator
     */
    public function __construct(
        \Magento\Framework\App\State $appState,
        ConfigWriterInterface $configWriter,
        ScopeConfigInterface $scopeConfig,
        ReinitableConfigInterface $reinitableConfig,
        Filesystem $filesystem,
        Json $jsonSerializer,
        ObjectManagerInterface $objectManager,
        ResourceConnection $resourceConnection,
        StoreManagerInterface $storeManager,
        ProductCollectionFactory $productCollectionFactory,
        Visibility $productVisibility,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        Config $config,
        FeedGenerator $feedGenerator
    ) {
        parent::__construct();
        $this->appState = $appState;
        $this->configWriter = $configWriter;
        $this->scopeConfig = $scopeConfig;
        $this->reinitableConfig = $reinitableConfig;
        $this->filesystem = $filesystem;
        $this->jsonSerializer = $jsonSerializer;
        $this->objectManager = $objectManager;
        $this->resourceConnection = $resourceConnection;
        $this->storeManager = $storeManager;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productVisibility = $productVisibility;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->config = $config;
        $this->feedGenerator = $feedGenerator;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('solosearch:feed:smoke-test');
        $this->setDescription(
            'Runs smoke tests for the feed generator against the real catalog, toggling SoloSearch '
            . 'config options one at a time and checking the generated feed reacts as expected. '
            . 'Every config change made during the run is reverted afterwards. Requires --force=1, '
            . 'so it never runs by accident from a copy/pasted command.'
        );
        $this->setDefinition([
            new \Symfony\Component\Console\Input\InputOption(
                self::PARAM_STORE,
                null,
                \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL,
                'Store view code or ID to test against',
                'default'
            ),
            new \Symfony\Component\Console\Input\InputOption(
                self::PARAM_FORCE,
                null,
                \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL,
                'Must be set to 1 to actually run - a safeguard against running this by accident',
                '0'
            ),
        ]);
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output)
    {
        if ($input->getOption(self::PARAM_FORCE) !== '1') {
            $output->writeln('<error>Refusing to run: this command changes real store configuration (temporarily).</error>');
            $output->writeln('Re-run with --force=1 to confirm you mean to do this: solosearch:feed:smoke-test --force=1');

            return \Symfony\Component\Console\Command\Command::FAILURE;
        }

        $this->appState->setAreaCode(Area::AREA_CRONTAB);

        $this->recoverFromInterruptedRun($output);

        $store = $this->storeManager->getStore($input->getOption(self::PARAM_STORE));
        $storeId = (int) $store->getId();

        $output->writeln("Running SoloSearch feed smoke tests against store '{$store->getCode()}' (id {$storeId}).");
        $output->writeln('Config changes made during the run are temporary and reverted afterwards.');
        $output->writeln('');

        $results = $this->withConfig(
            [
                'solosearch/general/enable' => 1,
                'solosearch/general/include_configurable_products' => 1,
                'cataloginventory/options/show_out_of_stock' => 1,
            ],
            $storeId,
            function () use ($output, $storeId) {
                return [
                    $this->testDisabledProductsAlwaysExcluded($output, $storeId),
                    $this->testVisibilityAlwaysExcluded($output, $storeId),
                    $this->testOutOfStock($output, $storeId),
                    $this->testIncludeConfigurableProducts($output, $storeId),
                    $this->testOtherProductTypeToggles($output, $storeId),
                    $this->testConfigurablePriceMode($output, $storeId),
                    $this->testConfigurablePriceModeFallback($output, $storeId),
                    $this->testBundlePrice($output, $storeId),
                    $this->testBundleSalePrice($output, $storeId),
                    $this->testStockFieldAlwaysPresent($output, $storeId),
                    $this->testCurrencyField($output, $storeId),
                    $this->testOnlyNavigableCategories($output, $storeId),
                    $this->testFeedPath($output, $storeId),
                    $this->testFieldMapping($output, $storeId),
                    $this->testGenerationTime($output),
                ];
            }
        );

        $this->cleanupTempDir();

        $passed = count(array_filter($results, function ($status) {
            return $status === 'pass';
        }));
        $skipped = count(array_filter($results, function ($status) {
            return $status === 'skip';
        }));
        $failed = count($results) - $passed - $skipped;

        $output->writeln('');
        $output->writeln("Result: {$passed} passed, {$failed} failed, {$skipped} skipped.");

        return $failed === 0
            ? \Symfony\Component\Console\Command\Command::SUCCESS
            : \Symfony\Component\Console\Command\Command::FAILURE;
    }

    // -------------------------------------------------------------------------
    // Scenarios
    // -------------------------------------------------------------------------

    /**
     * Disabled products always 404 on the storefront - there is no config toggle for this
     * (removed one on purpose), they must simply never appear in the feed.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testDisabledProductsAlwaysExcluded(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'disabled products always excluded';

        $product = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addAttributeToFilter('status', Status::STATUS_DISABLED);
        });

        if (!$product) {
            return $this->skip($output, $label, 'no disabled product found in the catalog to test with');
        }

        $sku = $product->getSku();
        $excluded = strpos($this->generateFeedContent($storeId, [$sku]), $sku) === false;

        return $this->report($output, $label, $excluded, "sku={$sku} excluded=" . $this->yesNo($excluded));
    }

    /**
     * "Not Visible Individually" and "Catalog"-only products are not meant to be found on their
     * own, and SoloSearch is a search feed - only VISIBILITY_IN_SEARCH / VISIBILITY_BOTH should
     * ever appear. No config toggle for this either, same reasoning as status.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testVisibilityAlwaysExcluded(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'visibility (not visible individually / catalog-only) always excluded';

        $notVisible = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addAttributeToFilter('visibility', Visibility::VISIBILITY_NOT_VISIBLE);
        });
        $catalogOnly = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addAttributeToFilter('visibility', Visibility::VISIBILITY_IN_CATALOG);
        });
        $visibleInSearch = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addAttributeToFilter('visibility', ['in' => $this->productVisibility->getVisibleInSearchIds()]);
        });

        if (!$notVisible && !$catalogOnly) {
            return $this->skip($output, $label, 'no "not visible individually" or "catalog"-only product found in the catalog to test with');
        }

        $skus = array_filter([
            $notVisible ? $notVisible->getSku() : null,
            $catalogOnly ? $catalogOnly->getSku() : null,
            $visibleInSearch ? $visibleInSearch->getSku() : null,
        ]);

        $content = $this->generateFeedContent($storeId, array_values($skus));

        $notVisibleOk = !$notVisible || strpos($content, $notVisible->getSku()) === false;
        $catalogOnlyOk = !$catalogOnly || strpos($content, $catalogOnly->getSku()) === false;
        $visibleInSearchOk = !$visibleInSearch || strpos($content, $visibleInSearch->getSku()) !== false;

        $pass = $notVisibleOk && $catalogOnlyOk && $visibleInSearchOk;

        return $this->report(
            $output,
            $label,
            $pass,
            'not_visible_excluded=' . $this->yesNo($notVisibleOk)
            . ' catalog_only_excluded=' . $this->yesNo($catalogOnlyOk)
            . ' visible_in_search_included=' . $this->yesNo($visibleInSearchOk)
        );
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testOutOfStock(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'out of stock mirrors cataloginventory show_out_of_stock';

        $product = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->joinField(
                'is_in_stock',
                'cataloginventory_stock_item',
                'is_in_stock',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'inner'
            );
            $collection->addFieldToFilter('is_in_stock', 0);
        });

        if (!$product) {
            return $this->skip($output, $label, 'no out-of-stock product found in the catalog to test with');
        }

        $sku = $product->getSku();

        // [Magento's global toggle, expected to be present]
        $combinations = [
            [0, false],
            [1, true],
        ];

        $allOk = true;
        $parts = [];

        foreach ($combinations as [$magento, $expected]) {
            $present = $this->withConfig(
                ['cataloginventory/options/show_out_of_stock' => $magento],
                $storeId,
                function () use ($storeId, $sku) {
                    return strpos($this->generateFeedContent($storeId, [$sku]), $sku) !== false;
                }
            );

            $ok = $present === $expected;
            $allOk = $allOk && $ok;
            $parts[] = "magento={$magento}=>" . ($ok ? 'ok' : 'FAIL');
        }

        return $this->report($output, $label, $allOk, "sku={$sku} " . implode(' ', $parts));
    }

    /**
     * Simple products must appear regardless of include_configurable_products - that toggle only
     * governs whether configurable products themselves are added on top. A simple that happens to
     * be a configurable's child and shouldn't be found on its own is excluded by the visibility
     * filter instead (see testVisibilityAlwaysExcluded), not by this toggle.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testIncludeConfigurableProducts(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'include_configurable_products (simple always included)';

        $simple = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addFieldToFilter('type_id', 'simple');
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
            $collection->addAttributeToFilter('visibility', ['in' => $this->productVisibility->getVisibleInSearchIds()]);
        });
        $configurable = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addFieldToFilter('type_id', 'configurable');
        });

        if (!$simple || !$configurable) {
            return $this->skip($output, $label, 'need at least one standalone simple and one configurable product in the catalog');
        }

        $simpleSku = $simple->getSku();
        $configurableSku = $configurable->getSku();

        // toggle => [expect simple present, expect configurable present]
        $toggles = [
            0 => [true, false],
            1 => [true, true],
        ];

        $allOk = true;
        $parts = [];

        foreach ($toggles as $toggle => [$expectSimple, $expectConfigurable]) {
            $content = $this->withConfig(['solosearch/general/include_configurable_products' => $toggle], $storeId, function () use ($storeId, $simpleSku, $configurableSku) {
                return $this->generateFeedContent($storeId, [$simpleSku, $configurableSku]);
            });

            $hasSimple = strpos($content, $simpleSku) !== false;
            $hasConfigurable = strpos($content, $configurableSku) !== false;
            $ok = $hasSimple === $expectSimple && $hasConfigurable === $expectConfigurable;
            $allOk = $allOk && $ok;
            $parts[] = "toggle={$toggle}=>" . ($ok ? 'ok' : 'FAIL');
        }

        return $this->report($output, $label, $allOk, "simple_sku={$simpleSku} configurable_sku={$configurableSku} " . implode(' ', $parts));
    }

    /**
     * Checks the independent include_bundle_products / include_virtual_products /
     * include_downloadable_products / include_grouped_products toggles, one type at a time.
     * Skips (per type, inside the details string) any type not present in the catalog; the whole
     * scenario is skipped only if none of the four types have any fixture to test with.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testOtherProductTypeToggles(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'include_bundle/virtual/downloadable/grouped_products';

        $typeConfigPaths = [
            'bundle' => 'solosearch/general/include_bundle_products',
            'virtual' => 'solosearch/general/include_virtual_products',
            'downloadable' => 'solosearch/general/include_downloadable_products',
            'grouped' => 'solosearch/general/include_grouped_products',
        ];

        $allOk = true;
        $parts = [];
        $anyTypeFound = false;

        foreach ($typeConfigPaths as $typeId => $configPath) {
            $product = $this->findOneProduct($storeId, function (ProductCollection $collection) use ($typeId) {
                $collection->addFieldToFilter('type_id', $typeId);
            });

            if (!$product) {
                $parts[] = "{$typeId}=>skip (none in catalog)";
                continue;
            }

            $anyTypeFound = true;
            $sku = $product->getSku();

            $excludedWhenOff = $this->withConfig([$configPath => 0], $storeId, function () use ($storeId, $sku) {
                return strpos($this->generateFeedContent($storeId, [$sku]), $sku) === false;
            });
            $includedWhenOn = $this->withConfig([$configPath => 1], $storeId, function () use ($storeId, $sku) {
                return strpos($this->generateFeedContent($storeId, [$sku]), $sku) !== false;
            });

            $ok = $excludedWhenOff && $includedWhenOn;
            $allOk = $allOk && $ok;
            $parts[] = "{$typeId}=>" . ($ok ? 'ok' : 'FAIL');
        }

        if (!$anyTypeFound) {
            return $this->skip($output, $label, 'no bundle/virtual/downloadable/grouped product found in the catalog to test with');
        }

        return $this->report($output, $label, $allOk, implode(' ', $parts));
    }

    /**
     * Checks that both configurable_price_mode strategies (cheapest enabled child, cheapest child
     * of any status) produce a non-empty price. The fallback from "cheapest enabled child" to "any
     * child" when none is enabled is covered separately by testConfigurablePriceModeFallback().
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testConfigurablePriceMode(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'configurable_price_mode';

        $configurable = $this->findConfigurableWithEnabledChild($storeId);

        if (!$configurable) {
            return $this->skip($output, $label, 'no configurable product with an enabled child variant found in the catalog to test with');
        }

        $sku = $configurable->getSku();

        $cheapestEnabledContent = $this->withConfig(
            ['solosearch/general/configurable_price_mode' => \Bydn\SoloSearch\Model\Source\ConfigurablePriceMode::CHEAPEST_CHILD],
            $storeId,
            function () use ($storeId, $sku) {
                return $this->generateFeedContent($storeId, [$sku]);
            }
        );
        $cheapestEnabledPrice = $this->extractFieldValue($cheapestEnabledContent, 'price');
        $cheapestEnabledOk = $cheapestEnabledPrice !== '';

        $cheapestAnyContent = $this->withConfig(
            ['solosearch/general/configurable_price_mode' => \Bydn\SoloSearch\Model\Source\ConfigurablePriceMode::CHEAPEST_CHILD_ANY],
            $storeId,
            function () use ($storeId, $sku) {
                return $this->generateFeedContent($storeId, [$sku]);
            }
        );
        $cheapestAnyPrice = $this->extractFieldValue($cheapestAnyContent, 'price');
        $cheapestAnyOk = $cheapestAnyPrice !== '';

        $pass = $cheapestEnabledOk && $cheapestAnyOk;

        return $this->report(
            $output,
            $label,
            $pass,
            "sku={$sku} cheapest_enabled_child_feed={$cheapestEnabledPrice}({$this->yesNo($cheapestEnabledOk)}) "
            . "cheapest_child_any_feed={$cheapestAnyPrice}({$this->yesNo($cheapestAnyOk)})"
        );
    }

    /**
     * Checks that "cheapest enabled child" falls back to considering disabled children too when a
     * configurable has no enabled child at all, instead of producing an empty price.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testConfigurablePriceModeFallback(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'configurable_price_mode (fallback to disabled children)';

        $configurable = $this->findConfigurableWithoutEnabledChild($storeId);

        if (!$configurable) {
            return $this->skip($output, $label, 'no configurable product with only disabled child variants found in the catalog to test with');
        }

        $sku = $configurable->getSku();

        $content = $this->withConfig(
            ['solosearch/general/configurable_price_mode' => \Bydn\SoloSearch\Model\Source\ConfigurablePriceMode::CHEAPEST_CHILD],
            $storeId,
            function () use ($storeId, $sku) {
                return $this->generateFeedContent($storeId, [$sku]);
            }
        );
        $price = $this->extractFieldValue($content, 'price');
        $pass = $price !== '';

        return $this->report($output, $label, $pass, "sku={$sku} price={$price}");
    }

    /**
     * Bundle products with dynamic pricing (price_type=0) have an empty/0 "price" attribute of
     * their own - checks that the feed still exports a non-empty, positive price for them, sourced
     * from the pricing framework's regular_price instead (see FeedGenerator::getBundlePriceValue()).
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testBundlePrice(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'bundle price (dynamic pricing)';

        $bundle = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addFieldToFilter('type_id', 'bundle');
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        });

        if (!$bundle) {
            return $this->skip($output, $label, 'no bundle product found in the catalog to test with');
        }

        $sku = $bundle->getSku();
        $price = $this->extractFieldValue($this->generateFeedContent($storeId, [$sku]), 'price');
        $pass = $price !== '' && (float) $price > 0;

        return $this->report($output, $label, $pass, "sku={$sku} price={$price}");
    }

    /**
     * For bundle products, "special_price" is stored as a discount PERCENTAGE (0-100), not a
     * currency amount (see Magento\Bundle\Pricing\Price\SpecialPrice) - checks that a bundle with
     * an active percentage discount produces a currency sale_price lower than price, not the raw
     * percentage number (see FeedGenerator::getBundlePriceValue()).
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testBundleSalePrice(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'bundle sale_price (percentage discount converted to currency)';

        $bundle = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addFieldToFilter('type_id', 'bundle');
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
            $collection->addAttributeToFilter('special_price', ['lt' => 100]);
        });

        if (!$bundle) {
            return $this->skip($output, $label, 'no bundle product with an active percentage discount (special_price < 100) found in the catalog to test with');
        }

        $sku = $bundle->getSku();
        $content = $this->generateFeedContent($storeId, [$sku]);
        $price = (float) $this->extractFieldValue($content, 'price');
        $salePrice = $this->extractFieldValue($content, 'sale_price');
        $pass = $salePrice !== '' && (float) $salePrice > 0 && (float) $salePrice < $price;

        return $this->report($output, $label, $pass, "sku={$sku} price={$price} sale_price={$salePrice}");
    }

    /**
     * The "availability" field is structural (not part of field_mapping) and must always be
     * present, with a value of "in_stock" or "out_of_stock".
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testStockFieldAlwaysPresent(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'availability field always present';

        $product = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        });

        if (!$product) {
            return $this->skip($output, $label, 'no product found in the catalog to test with');
        }

        $sku = $product->getSku();
        $availabilityValue = $this->extractFieldValue($this->generateFeedContent($storeId, [$sku]), 'availability');
        $pass = in_array($availabilityValue, ['in_stock', 'out_of_stock'], true);

        return $this->report($output, $label, $pass, "sku={$sku} availability={$availabilityValue}");
    }

    /**
     * The "currency" field is structural (not part of field_mapping) and must always match the
     * store view's default currency code.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testCurrencyField(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'currency field';

        $product = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        });

        if (!$product) {
            return $this->skip($output, $label, 'no product found in the catalog to test with');
        }

        $sku = $product->getSku();
        $expectedCurrency = $this->storeManager->getStore($storeId)->getDefaultCurrencyCode();
        $currencyValue = $this->extractFieldValue($this->generateFeedContent($storeId, [$sku]), 'currency');
        $pass = $currencyValue === $expectedCurrency;

        return $this->report($output, $label, $pass, "sku={$sku} expected={$expectedCurrency} got={$currencyValue}");
    }

    /**
     * Checks that only_navigable_categories actually removes a non-navigable category from a
     * product's exported categories, and that it is present when the toggle is off.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testOnlyNavigableCategories(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'only_navigable_categories';

        $nonNavigableCategory = $this->findOneCategory($storeId, false);

        if (!$nonNavigableCategory) {
            return $this->skip($output, $label, 'no category excluded from the navigation menu found to test with');
        }

        $product = $this->findOneProduct($storeId, function (ProductCollection $collection) use ($nonNavigableCategory) {
            $collection->addCategoriesFilter(['eq' => [$nonNavigableCategory->getId()]]);
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        });

        if (!$product) {
            return $this->skip($output, $label, 'no enabled product assigned to a non-navigable category found to test with');
        }

        $sku = $product->getSku();
        $categoryName = $nonNavigableCategory->getName();

        $includedWhenOff = $this->withConfig(['solosearch/general/only_navigable_categories' => 0], $storeId, function () use ($storeId, $sku, $categoryName) {
            return strpos($this->generateFeedContent($storeId, [$sku]), $categoryName) !== false;
        });

        $excludedWhenOn = $this->withConfig(['solosearch/general/only_navigable_categories' => 1], $storeId, function () use ($storeId, $sku, $categoryName) {
            return strpos($this->generateFeedContent($storeId, [$sku]), $categoryName) === false;
        });

        $pass = $includedWhenOff && $excludedWhenOn;

        return $this->report(
            $output,
            $label,
            $pass,
            "sku={$sku} category='{$categoryName}' included_when_off=" . $this->yesNo($includedWhenOff)
            . ' excluded_when_on=' . $this->yesNo($excludedWhenOn)
        );
    }

    /**
     * Verifies both the default (computed) feed path and a custom override are honored.
     * Restricted to a single SKU: this check is only about path resolution, not about how long a
     * full catalog generation takes - that's a separate concern, best verified by running
     * `solosearch:feed:generate` directly without a SKU restriction.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testFeedPath(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'feed_path (default + override)';
        $rootDir = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);

        $anyProduct = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        });
        $skus = $anyProduct ? [$anyProduct->getSku()] : [];

        $this->feedGenerator->generate($storeId, $skus);
        $defaultPath = $this->config->getFeedPath($storeId);
        $defaultOk = $rootDir->isExist($defaultPath);

        $customPath = self::TEMP_DIR . '/custom-path.xml';
        $this->withConfig(['solosearch/feed_generation/feed_path' => $customPath], $storeId, function () use ($storeId, $skus) {
            $this->feedGenerator->generate($storeId, $skus);
        });
        $overrideOk = $rootDir->isExist($customPath);

        $pass = $defaultOk && $overrideOk;

        return $this->report(
            $output,
            $label,
            $pass,
            "default={$defaultPath}(" . $this->yesNo($defaultOk) . ") override={$customPath}(" . $this->yesNo($overrideOk) . ')'
        );
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $storeId
     * @return string
     */
    private function testFieldMapping(\Symfony\Component\Console\Output\OutputInterface $output, $storeId)
    {
        $label = 'field_mapping';

        $product = $this->findOneProduct($storeId, function (ProductCollection $collection) {
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        });

        if (!$product) {
            return $this->skip($output, $label, 'no enabled product found in the catalog to test with');
        }

        $mappingJson = $this->jsonSerializer->serialize([
            'row1' => ['feed_field' => 'smoke_test_field', 'attribute_code' => 'sku'],
        ]);

        $sku = $product->getSku();

        $content = $this->withConfig(['solosearch/field_mapping/mapping' => $mappingJson], $storeId, function () use ($storeId, $sku) {
            return $this->generateFeedContent($storeId, [$sku]);
        });

        $pass = strpos($content, '<smoke_test_field>') !== false && strpos($content, $product->getSku()) !== false;

        return $this->report($output, $label, $pass, 'custom mapping smoke_test_field=>sku rendered as <smoke_test_field>');
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return string
     */
    private function testGenerationTime(\Symfony\Component\Console\Output\OutputInterface $output)
    {
        $label = 'generation_time backend model';
        $cronPath = GenerationTime::CRON_STRING_PATH;
        $original = $this->scopeConfig->getValue($cronPath);
        $this->rememberOriginal($cronPath, 0, $original);

        try {
            /** @var GenerationTime $backendModel */
            $backendModel = $this->objectManager->create(GenerationTime::class);
            $backendModel->setPath('solosearch/feed_generation/generation_time');
            $backendModel->setValue('5,15,0');
            $backendModel->setScope('default');
            $backendModel->setScopeId(0);
            $backendModel->afterSave();

            $this->reinitableConfig->reinit();
            $newValue = $this->scopeConfig->getValue($cronPath);

            $pass = $newValue === '15 5 * * *';

            return $this->report($output, $label, $pass, "5,15,0 (5AM 15min) => expected '15 5 * * *', got '{$newValue}'");
        } finally {
            if ($original === null) {
                $this->configWriter->delete($cronPath);
            } else {
                $this->configWriter->save($cronPath, $original);
            }
            $this->forgetOriginal($cronPath, 0);
            $this->reinitableConfig->reinit();
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Regenerates the feed into a scratch path under var/ (never the real configured path) and
     * returns its content. $skus restricts generation to those SKUs only, which is what keeps
     * the smoke test fast - most scenarios only care about one or two known products, not the
     * whole catalog.
     *
     * @param int $storeId
     * @param array $skus
     * @return string
     */
    private function generateFeedContent($storeId, array $skus = [])
    {
        $scratchPath = self::TEMP_DIR . '/scratch.xml';

        return $this->withConfig(['solosearch/feed_generation/feed_path' => $scratchPath], $storeId, function () use ($storeId, $scratchPath, $skus) {
            $this->feedGenerator->generate($storeId, $skus);

            return $this->filesystem->getDirectoryRead(DirectoryList::ROOT)->readFile($scratchPath);
        });
    }

    /**
     * @param int $storeId
     * @param callable $configureCollection
     * @return \Magento\Catalog\Model\Product|null
     */
    private function findOneProduct($storeId, callable $configureCollection)
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->setPageSize(1);
        $configureCollection($collection);

        foreach ($collection as $product) {
            return $product;
        }

        return null;
    }

    /**
     * @param int $storeId
     * @param bool $includeInMenu
     * @return \Magento\Catalog\Model\Category|null
     */
    private function findOneCategory($storeId, $includeInMenu)
    {
        $categories = $this->categoryCollectionFactory->create();
        $categories->setStoreId($storeId);
        $categories->addAttributeToSelect(['name', 'include_in_menu']);
        $categories->addAttributeToFilter('include_in_menu', (int) $includeInMenu);
        $categories->setPageSize(1);

        foreach ($categories as $category) {
            return $category;
        }

        return null;
    }

    /**
     * Scans a sample of configurable products for the first one that has at least one enabled
     * child variant - not every configurable does (all its children can be disabled), and that
     * makes for a bad fixture for testConfigurablePriceMode().
     *
     * @param int $storeId
     * @return \Magento\Catalog\Model\Product|null
     */
    private function findConfigurableWithEnabledChild($storeId)
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addFieldToFilter('type_id', 'configurable');
        $collection->setPageSize(20);

        foreach ($collection as $configurable) {
            foreach ($configurable->getTypeInstance()->getUsedProducts($configurable) as $child) {
                if ((int) $child->getStatus() === Status::STATUS_ENABLED) {
                    return $configurable;
                }
            }
        }

        return null;
    }

    /**
     * Scans a sample of configurable products for the first one that has at least one child
     * variant but none of them enabled - the fixture needed to prove the "cheapest enabled child"
     * mode falls back to disabled children instead of producing an empty price.
     *
     * @param int $storeId
     * @return \Magento\Catalog\Model\Product|null
     */
    private function findConfigurableWithoutEnabledChild($storeId)
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addFieldToFilter('type_id', 'configurable');
        $collection->setPageSize(20);

        foreach ($collection as $configurable) {
            $children = $configurable->getTypeInstance()->getUsedProducts($configurable);

            if (empty($children)) {
                continue;
            }

            $hasEnabledChild = false;

            foreach ($children as $child) {
                if ((int) $child->getStatus() === Status::STATUS_ENABLED) {
                    $hasEnabledChild = true;

                    break;
                }
            }

            if (!$hasEnabledChild) {
                return $configurable;
            }
        }

        return null;
    }

    /**
     * Extracts the CDATA value of a <$fieldName> node from a generated feed's raw XML content
     *
     * @param string $xmlContent
     * @param string $fieldName
     * @return string
     */
    private function extractFieldValue($xmlContent, $fieldName)
    {
        $pattern = '/<' . preg_quote($fieldName, '/') . '><!\[CDATA\[(.*?)\]\]><\/' . preg_quote($fieldName, '/') . '>/s';

        return preg_match($pattern, $xmlContent, $matches) ? $matches[1] : '';
    }

    /**
     * Writes every given config path/value pair (store scope), runs the callback, then restores
     * every path to its original value (or removes it if it had none). Reentrant: nested calls
     * only ever touch their own paths.
     *
     * The original value is read directly from core_config_data (getRawStoreConfigValue), not via
     * ScopeConfigInterface::getValue(): the latter falls back to config.xml defaults when no row
     * exists, which for field_mapping/mapping is a native PHP array - saving that back through
     * the config writer crashes a third-party plugin (Magento_ServicesId) that expects a string.
     * Reading the raw row avoids that entirely and also means "no row before" correctly deletes
     * afterwards instead of writing a redundant explicit row.
     *
     * Every original value is also durably recorded (rememberOriginal) before being overwritten,
     * and forgotten (forgetOriginal) only once successfully restored - see CONFIG_BACKUP_FILE.
     *
     * @param array $pathValues
     * @param int $storeId
     * @param callable $callback
     * @return mixed
     */
    private function withConfig(array $pathValues, $storeId, callable $callback)
    {
        $originals = [];

        foreach ($pathValues as $path => $value) {
            $originals[$path] = $this->getRawStoreConfigValue($path, $storeId);
            $this->rememberOriginal($path, $storeId, $originals[$path]);
            $this->configWriter->save($path, $value, ScopeInterface::SCOPE_STORES, $storeId);
        }

        $this->reinitableConfig->reinit();

        try {
            return $callback();
        } finally {
            foreach ($originals as $path => $value) {
                if ($value === null) {
                    $this->configWriter->delete($path, ScopeInterface::SCOPE_STORES, $storeId);
                } else {
                    $this->configWriter->save($path, $value, ScopeInterface::SCOPE_STORES, $storeId);
                }
                $this->forgetOriginal($path, $storeId);
            }
            $this->reinitableConfig->reinit();
        }
    }

    /**
     * Reads the raw stored value of a store-scope config row, or null if no such row exists.
     * Deliberately bypasses ScopeConfigInterface (see withConfig() docblock).
     *
     * @param string $path
     * @param int $storeId
     * @return string|null
     */
    private function getRawStoreConfigValue($path, $storeId)
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('core_config_data'), 'value')
            ->where('scope = ?', ScopeInterface::SCOPE_STORES)
            ->where('scope_id = ?', $storeId)
            ->where('path = ?', $path);

        $value = $connection->fetchOne($select);

        return $value === false ? null : $value;
    }

    /**
     * If a previous run of this command was killed/crashed before it could restore everything it
     * changed, CONFIG_BACKUP_FILE still holds a record of it. Restore those values first, so a
     * fresh run always starts from a clean slate instead of silently building on stale test config.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return void
     */
    private function recoverFromInterruptedRun(\Symfony\Component\Console\Output\OutputInterface $output)
    {
        $entries = $this->readConfigBackup();

        if (empty($entries)) {
            return;
        }

        $output->writeln('<comment>Found config left over from a previous interrupted run - restoring it before continuing:</comment>');

        foreach ($entries as $entry) {
            if ($entry['value'] === null) {
                $this->configWriter->delete($entry['path'], ScopeInterface::SCOPE_STORES, $entry['scope_id']);
            } else {
                $this->configWriter->save($entry['path'], $entry['value'], ScopeInterface::SCOPE_STORES, $entry['scope_id']);
            }
            $output->writeln("  restored {$entry['path']} (scope_id {$entry['scope_id']})");
        }

        $this->writeConfigBackup([]);
        $this->reinitableConfig->reinit();
        $output->writeln('');
    }

    /**
     * Durably records that $path (scope_id $scopeId) held $value before we are about to change it,
     * so recoverFromInterruptedRun() can restore it even if this process never gets to.
     *
     * @param string $path
     * @param int $scopeId
     * @param string|null $value
     * @return void
     */
    private function rememberOriginal($path, $scopeId, $value)
    {
        $entries = $this->readConfigBackup();
        $entries[] = ['path' => $path, 'scope_id' => $scopeId, 'value' => $value];
        $this->writeConfigBackup($entries);
    }

    /**
     * Removes the most recent backup entry for $path/$scopeId now that it has been restored.
     *
     * @param string $path
     * @param int $scopeId
     * @return void
     */
    private function forgetOriginal($path, $scopeId)
    {
        $entries = $this->readConfigBackup();

        foreach (array_keys($entries) as $index) {
            if ($entries[$index]['path'] === $path && $entries[$index]['scope_id'] === $scopeId) {
                unset($entries[$index]);
                break;
            }
        }

        $this->writeConfigBackup(array_values($entries));
    }

    /**
     * @return array
     */
    private function readConfigBackup()
    {
        $rootDir = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);

        if (!$rootDir->isExist(self::CONFIG_BACKUP_FILE)) {
            return [];
        }

        try {
            return $this->jsonSerializer->unserialize($rootDir->readFile(self::CONFIG_BACKUP_FILE)) ?: [];
        } catch (\InvalidArgumentException $e) {
            return [];
        }
    }

    /**
     * @param array $entries
     * @return void
     */
    private function writeConfigBackup(array $entries)
    {
        $rootDir = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);

        if (empty($entries)) {
            if ($rootDir->isExist(self::CONFIG_BACKUP_FILE)) {
                $rootDir->delete(self::CONFIG_BACKUP_FILE);
            }

            return;
        }

        $rootDir->create(self::TEMP_DIR);
        $rootDir->writeFile(self::CONFIG_BACKUP_FILE, $this->jsonSerializer->serialize($entries));
    }

    /**
     * @return void
     */
    private function cleanupTempDir()
    {
        $rootDir = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);

        if ($rootDir->isExist(self::TEMP_DIR)) {
            $rootDir->delete(self::TEMP_DIR);
        }
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $label
     * @param bool $pass
     * @param string $details
     * @return string
     */
    private function report(\Symfony\Component\Console\Output\OutputInterface $output, $label, $pass, $details = '')
    {
        return $this->printResult($output, $label, $pass ? 'pass' : 'fail', $details);
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $label
     * @param string $reason
     * @return string
     */
    private function skip(\Symfony\Component\Console\Output\OutputInterface $output, $label, $reason)
    {
        return $this->printResult($output, $label, 'skip', $reason);
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $label
     * @param string $status
     * @param string $details
     * @return string
     */
    private function printResult(\Symfony\Component\Console\Output\OutputInterface $output, $label, $status, $details)
    {
        $icons = ['pass' => '[PASS]', 'fail' => '[FAIL]', 'skip' => '[SKIP]'];
        $line = $icons[$status] . ' ' . $label;

        if ($details !== '') {
            $line .= ' - ' . $details;
        }

        $output->writeln($line);

        return $status;
    }

    /**
     * @param bool $value
     * @return string
     */
    private function yesNo($value)
    {
        return $value ? 'yes' : 'no';
    }
}
