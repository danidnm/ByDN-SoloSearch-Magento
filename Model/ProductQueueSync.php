<?php

namespace Bydn\SoloSearch\Model;

use Bydn\SoloSearch\Api\Data\ProductQueueItemInterface;
use Bydn\SoloSearch\Api\ProductQueueItemRepositoryInterface;
use Bydn\SoloSearch\Helper\Config;

/**
 * Sends pending queued product changes (see Api\Data\ProductQueueItemInterface) to SoloSearch's
 * single-product API - the real-time counterpart to FeedGenerator's scheduled full Feed Reindex.
 * Called by Cron\SyncProductQueue and the solosearch:product-queue:sync console command, both kept
 * thin on purpose - this class owns the actual logic.
 *
 * Depends on ProductFieldsBuilder, not FeedGenerator - the two are siblings, both consuming the
 * same field-building logic for their own different purpose (a whole-catalogue XML file here vs a
 * per-product JSON payload there), neither depends on the other.
 */
class ProductQueueSync
{
    // Matches the API's own per-request cap (see suite's OpenSearchClient::BULK_BATCH_SIZE) - no
    // point pulling more pending rows than a single batch call could ever send.
    const BATCH_SIZE = 500;

    // A row that keeps failing for the same reason (e.g. a permanently missing required field)
    // would otherwise retry forever - give up and drop it after this many failed attempts, logging
    // the final error so it's not silently lost.
    const MAX_ATTEMPTS = 5;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ProductQueueItemRepositoryInterface
     */
    private $queueRepository;

    /**
     * @var ProductFieldsBuilder
     */
    private $fieldsBuilder;

    /**
     * @var SoloSearchClient
     */
    private $soloSearchClient;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param Config $config
     * @param ProductQueueItemRepositoryInterface $queueRepository
     * @param ProductFieldsBuilder $fieldsBuilder
     * @param SoloSearchClient $soloSearchClient
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        Config $config,
        ProductQueueItemRepositoryInterface $queueRepository,
        ProductFieldsBuilder $fieldsBuilder,
        SoloSearchClient $soloSearchClient,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->queueRepository = $queueRepository;
        $this->fieldsBuilder = $fieldsBuilder;
        $this->soloSearchClient = $soloSearchClient;
        $this->logger = $logger;
    }

    /**
     * Syncs every store view's pending queue (skipping disabled ones), one store at a time so a
     * failure in one doesn't stop the rest - mirrors
     * FeedGenerator::generateForAllStores()/generateOneOfManyStores().
     *
     * @return void
     */
    public function syncAllStores()
    {
        foreach ($this->storeManager->getStores() as $store) {
            $this->syncOneOfManyStores((int) $store->getId());
        }
    }

    /**
     * @param int $storeId
     * @return void
     */
    private function syncOneOfManyStores($storeId)
    {
        try {
            $this->syncStoreIfEnabled($storeId);
        } catch (\Throwable $e) {
            $this->logger->error(__METHOD__ . ": store {$storeId} failed, skipping to the next store - " . $e->getMessage());
        }
    }

    /**
     * @param int $storeId
     * @return void
     */
    public function syncStoreIfEnabled($storeId)
    {
        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        $this->syncStore($storeId);
    }

    /**
     * Sends up to BATCH_SIZE pending items for a single store: resolves updates to their current
     * product data (a product no longer matching the feed's own inclusion filters is treated as a
     * delete instead - see ProductFieldsBuilder::buildFieldsForProducts()), sends the update batch in one
     * call, and sends deletes one by one (no batch-delete endpoint exists yet).
     *
     * @param int $storeId
     * @return void
     */
    public function syncStore($storeId)
    {
        $items = $this->queueRepository->getPendingItems($storeId, self::BATCH_SIZE);

        if (empty($items)) {
            return;
        }

        $updateItems = [];
        $deleteItems = [];

        foreach ($items as $item) {
            if ($item->getOperation() === ProductQueueItemInterface::OPERATION_DELETE) {
                $deleteItems[] = $item;
            } else {
                $updateItems[] = $item;
            }
        }

        $indexed = 0;
        $failed = 0;

        if (!empty($updateItems)) {
            [$indexed, $failed, $reclassifiedAsDeletes] = $this->syncUpdates($storeId, $updateItems);
            $deleteItems = array_merge($deleteItems, $reclassifiedAsDeletes);
        }

        $deleted = 0;

        if (!empty($deleteItems)) {
            [$deleted, $deleteFailed] = $this->syncDeletes($storeId, $deleteItems);
            $failed += $deleteFailed;
        }

        $this->logger->info(__METHOD__ . ": store {$storeId} - {$indexed} updated, {$deleted} deleted, {$failed} failed");
    }

    /**
     * @param int $storeId
     * @param ProductQueueItemInterface[] $items
     * @return array{0: int, 1: int, 2: ProductQueueItemInterface[]}
     */
    private function syncUpdates($storeId, array $items)
    {
        $productIds = array_map(function (ProductQueueItemInterface $item) {
            return $item->getProductId();
        }, $items);

        $fieldsByProductId = $this->fieldsBuilder->buildFieldsForProducts($storeId, $productIds);

        $itemsByProductId = [];
        $payload = [];
        $toDelete = [];

        foreach ($items as $item) {
            $productId = $item->getProductId();
            $itemsByProductId[$productId] = $item;

            if (isset($fieldsByProductId[$productId])) {
                $payload[] = $fieldsByProductId[$productId];
            } else {
                // No longer matches the feed's own inclusion filters (disabled, hidden, wrong
                // type...) - remove it from the index instead of sending stale/incomplete data.
                $toDelete[] = $item;
            }
        }

        if (empty($payload)) {
            return [0, 0, $toDelete];
        }

        $results = $this->soloSearchClient->sendProductBatch($storeId, $payload);

        if ($results === null) {
            // Nothing sent - every row stays pending as-is, retried on the next run.
            return [0, 0, $toDelete];
        }

        $indexed = 0;
        $failed = 0;

        foreach ($results as $result) {
            $item = $itemsByProductId[$result['id']] ?? null;

            if ($item === null) {
                continue;
            }

            if ($result['success']) {
                $this->finishItem($item);
                $indexed++;
            } else {
                $this->failItem($item, (string) $result['error']);
                $failed++;
            }
        }

        return [$indexed, $failed, $toDelete];
    }

    /**
     * @param int $storeId
     * @param ProductQueueItemInterface[] $items
     * @return array{0: int, 1: int}
     */
    private function syncDeletes($storeId, array $items)
    {
        $deleted = 0;
        $failed = 0;

        foreach ($items as $item) {
            if ($this->soloSearchClient->deleteProduct($storeId, $item->getProductId())) {
                $this->finishItem($item);
                $deleted++;
            } else {
                $this->failItem($item, 'Delete request failed - see the SoloSearch log for details.');
                $failed++;
            }
        }

        return [$deleted, $failed];
    }

    /**
     * A queue row that was sent successfully has nothing left to do here.
     *
     * @param ProductQueueItemInterface $item
     * @return void
     */
    private function finishItem(ProductQueueItemInterface $item)
    {
        try {
            $this->queueRepository->delete($item);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ": failed to remove queue item {$item->getEntityId()} after a successful send - " . $e->getMessage());
        }
    }

    /**
     * Records a failed send attempt. Gives up (drops the row, logging why) once MAX_ATTEMPTS is
     * reached, instead of retrying a permanently-failing product forever.
     *
     * @param ProductQueueItemInterface $item
     * @param string $error
     * @return void
     */
    private function failItem(ProductQueueItemInterface $item, $error)
    {
        $attempts = $item->getAttempts() + 1;

        try {
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->logger->error(sprintf(
                    'ProductQueueSync: giving up on product %s (store %s) after %s attempts - %s',
                    $item->getProductId(),
                    $item->getStoreId(),
                    $attempts,
                    $error
                ));
                $this->queueRepository->delete($item);

                return;
            }

            $item->setAttempts($attempts);
            $item->setStatus(ProductQueueItemInterface::STATUS_ERROR);
            $item->setResult($error);
            $this->queueRepository->save($item);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ": failed to record failure for queue item {$item->getEntityId()} - " . $e->getMessage());
        }
    }
}
