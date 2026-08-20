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
    // would otherwise retry forever - it stays STATUS_PENDING and is retried on every syncStore()
    // run up to this many attempts, then moves to the terminal STATUS_ERROR (see
    // ProductQueueSync::failItem()) instead of being retried indefinitely.
    const MAX_ATTEMPTS = 3;

    // Set as a pending item's `result` when real-time sync is turned off (Product Queue > Enable
    // Real-Time Sync) and the sync cron finds it still waiting - see rejectPendingItems(). Same
    // wording style as the other terminal messages in this class (e.g. syncDeletes()'s failure).
    const REALTIME_SYNC_DISABLED_MESSAGE = 'Real-time sync is disabled.';

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

        if (!$this->config->isRealtimeSyncEnabled($storeId)) {
            $this->rejectPendingItems($storeId);

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
        $indexed = 0;
        $failed = 0;
        $toDelete = [];

        // Get product ids and classify items by id
        $productIds = [];
        $itemsByProductId = [];
        foreach ($items as $item) {
            $productIds[] = $item->getProductId();
            $itemsByProductId[$item->getProductId()] = $item;
        }

        // Generate payload for every item
        $payload = [];
        $generatedIds = [];
        foreach ($this->fieldsBuilder->getCollection($storeId, [], $productIds) as $product) {
            $productId = $product->getId();
            $generatedIds[] = $productId;
            $payload[$productId] = $this->fieldsBuilder->buildFields($product);
        }

        // Reclasify items as deletes if them does not match the product collection restriction
        foreach ($items as $item) {
            if (!in_array($item->getProductId(), $generatedIds)) {
                $toDelete[] = $item;
            }
        }

        if (!empty($payload)) {

            $results = $this->soloSearchClient->sendProductBatch($storeId, $payload);
            
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
     * A queue row that was sent successfully is kept, not deleted - marked STATUS_SUCCESS as a
     * visibility/audit trail (see the interface's class docblock). `attempts` is left as-is
     * (however many it took), not reset - it's useful information here, not just retry-budget
     * bookkeeping.
     *
     * @param ProductQueueItemInterface $item
     * @return void
     */
    private function finishItem(ProductQueueItemInterface $item)
    {
        try {
            $item->setStatus(ProductQueueItemInterface::STATUS_SUCCESS);
            $item->setResult(null);
            $this->queueRepository->save($item);

            $this->logger->info(sprintf(
                __METHOD__ . ': product %s (store %s) %s succeeded',
                $item->getProductId(),
                $item->getStoreId(),
                $item->getOperation()
            ));
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ": failed to mark queue item {$item->getEntityId()} as successful - " . $e->getMessage());
        }
    }

    /**
     * Records a failed send attempt. Stays STATUS_PENDING (so the next syncStore() run picks it up
     * and retries it) as long as `attempts` is still under MAX_ATTEMPTS; only moves to the
     * terminal STATUS_ERROR once that limit is reached, at which point it's kept, not deleted, same
     * as a successful item (see finishItem()) - `attempts` on the row shows it gave up.
     *
     * @param ProductQueueItemInterface $item
     * @param string $error
     * @return void
     */
    private function failItem(ProductQueueItemInterface $item, $error)
    {
        $attempts = $item->getAttempts() + 1;
        $exhausted = $attempts >= self::MAX_ATTEMPTS;

        try {
            $item->setAttempts($attempts);
            $item->setResult($error);

            if ($exhausted) {
                $item->setStatus(ProductQueueItemInterface::STATUS_ERROR);
            }

            $this->queueRepository->save($item);

            $this->logger->{$exhausted ? 'error' : 'warning'}(sprintf(
                'ProductQueueSync: product %s (store %s) %s failed (attempt %s/%s)%s - %s',
                $item->getProductId(),
                $item->getStoreId(),
                $item->getOperation(),
                $attempts,
                self::MAX_ATTEMPTS,
                $exhausted ? ', giving up' : ', will retry',
                $error
            ));
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ": failed to record failure for queue item {$item->getEntityId()} - " . $e->getMessage());
        }
    }

    /**
     * Real-time sync is off for this store - whatever is still pending never gets sent. Every
     * pending item is rejected outright (see rejectItem()) rather than left in the queue: with
     * ProductChangeNotifier also gated on the same setting, nothing new is being queued while this
     * is off, so there is nothing to gain by leaving old rows waiting for a setting change that may
     * never come. Re-enabling doesn't need these rows back either - the next real product change
     * re-queues fresh work via ProductChangeNotifier::enqueue() regardless.
     *
     * @param int $storeId
     * @return void
     */
    private function rejectPendingItems($storeId)
    {
        $items = $this->queueRepository->getPendingItems($storeId, self::BATCH_SIZE);

        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            $this->rejectItem($item);
        }

        $this->logger->info(__METHOD__ . ": store {$storeId} - " . count($items) . ' item(s) rejected, real-time sync disabled');
    }

    /**
     * Marks a single pending item as a terminal STATUS_ERROR because real-time sync itself is
     * disabled - not a genuine send failure, so unlike failItem() this never retries: `attempts` is
     * left untouched (nothing was actually attempted) and it goes straight to STATUS_ERROR
     * regardless of MAX_ATTEMPTS, since retrying would just find the setting still off.
     *
     * @param ProductQueueItemInterface $item
     * @return void
     */
    private function rejectItem(ProductQueueItemInterface $item)
    {
        try {
            $item->setStatus(ProductQueueItemInterface::STATUS_ERROR);
            $item->setResult(self::REALTIME_SYNC_DISABLED_MESSAGE);
            $this->queueRepository->save($item);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ": failed to reject queue item {$item->getEntityId()} - " . $e->getMessage());
        }
    }
}
