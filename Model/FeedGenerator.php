<?php

namespace Bydn\SoloSearch\Model;

use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Orchestrates the scheduled/manual full Feed Reindex: builds the whole-catalogue XML file and
 * writes it to disk. Per-product field computation itself lives in ProductFieldsBuilder, shared
 * with the real-time single-product sync (Model\ProductQueueSync) - this class only decides how to
 * render those fields as the feed's XML and where to write it.
 */
class FeedGenerator
{
    /**
     * @var \Magento\Framework\Filesystem
     */
    private $filesystem;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ProductFieldsBuilder
     */
    private $fieldsBuilder;

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
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param ProductFieldsBuilder $fieldsBuilder
     * @param \Bydn\SoloSearch\Model\SoloSearchClient $soloSearchClient
     * @param \Bydn\SoloSearch\Helper\Config $config
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        ProductFieldsBuilder $fieldsBuilder,
        \Bydn\SoloSearch\Model\SoloSearchClient $soloSearchClient,
        \Bydn\SoloSearch\Helper\Config $config,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->storeManager = $storeManager;
        $this->fieldsBuilder = $fieldsBuilder;
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
     * Deliberately not called from generate() itself: that method also accepts a $skus restriction
     * for targeted/manual testing, and content generated that way is never what SoloSearch's own
     * feed path serves - notifying it to reindex from there would be pointless at best, and would
     * burn through the reindex endpoint's rate limit at worst.
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
     * Not used in production (Cron/Console always generate the full feed) - kept for targeted
     * testing tools (manual or automated) to regenerate against a couple of known products
     * instead of the entire catalog.
     *
     * @param int $storeId
     * @param array $skus
     * @return void
     */
    public function generate($storeId, array $skus = [])
    {
        $this->logger->info(__METHOD__ . ": start (store {$storeId})");

        $this->storeId = $storeId;

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

        foreach ($this->fieldsBuilder->getCollection($this->storeId, $skus) as $product) {
            $channel->appendChild($this->buildItemNode($document, $product));
        }

        return $document->saveXML();
    }

    /**
     * Builds the <item> node for a single product, from ProductFieldsBuilder::buildFields().
     *
     * @param \DOMDocument $document
     * @param \Magento\Catalog\Model\Product $product
     * @return \DOMElement
     */
    private function buildItemNode(\DOMDocument $document, \Magento\Catalog\Model\Product $product)
    {
        $item = $document->createElement('item');

        // Structural fields are always written even when empty (last param false); everything
        // else - including mapped fields - is skipped when empty.
        $alwaysWritten = ['id', 'sku', 'availability', 'disable_add_to_cart'];

        foreach ($this->fieldsBuilder->buildFields($product) as $name => $value) {
            $this->appendNode($document, $item, $name, $value, true, !in_array($name, $alwaysWritten, true));
        }

        return $item;
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
