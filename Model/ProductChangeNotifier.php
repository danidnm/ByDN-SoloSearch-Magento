<?php

namespace Bydn\SoloSearch\Model;

use Bydn\SoloSearch\Api\Data\ProductQueueItemInterface;
use Bydn\SoloSearch\Api\ProductChangeNotifierInterface;
use Bydn\SoloSearch\Api\ProductQueueItemRepositoryInterface;
use Bydn\SoloSearch\Helper\Config;

class ProductChangeNotifier implements ProductChangeNotifierInterface
{
    /**
     * Attribute codes behind the structural feed fields (see ProductFieldsBuilder::buildFields())
     * that can realistically be watched via $product->dataHasChangedFor() on the product itself.
     *
     * Built on top of ProductFieldsBuilder::STRUCTURAL_ATTRIBUTE_CODES rather than duplicating it -
     * that list feeds $collection->addAttributeToSelect() (see ProductFieldsBuilder::getCollection()),
     * so it deliberately excludes codes loaded through other collection methods instead: `sku`/
     * `type_id` (native columns, always present, never need explicit selecting) and `url_key`/
     * `category_ids` (loaded via addUrlRewrite()/addCategoryIds(), not addAttributeToSelect() -
     * category_ids in particular isn't a normal EAV attribute, adding it there could behave
     * unexpectedly). Those four still need to be in THIS list, since their value changing is
     * exactly what we're watching for - they're just never something ProductFieldsBuilder needs to
     * additionally ask the collection to select.
     *
     * `status`/`visibility` are also included, even though neither appears in the feed itself -
     * they control whether the product qualifies for the feed at all (see
     * ProductFieldsBuilder::getCollection()'s filters). A change to either is still handled as a
     * normal "changed" signal here; ProductSaveAfter is the one that inspects the product's
     * current status/visibility (via ProductFieldsBuilder::isVisibleInFeed()) to decide whether
     * that means an update or a removal from the index.
     *
     * Deliberately excludes two known gaps, left for later:
     *  - stock/availability, which isn't a product attribute at all (separate stock/MSI entity,
     *    never touched by catalog_product_save_after);
     *  - a configurable's price/special_price when only a CHILD product's price actually changed
     *    (the save event fires on the child, not the parent that appears in the feed - the parent
     *    would need to be resolved and queued separately).
     */
    const EXTRA_WATCHED_ATTRIBUTE_CODES = ['sku', 'url_key', 'category_ids', 'type_id', 'status', 'visibility'];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ProductQueueItemRepositoryInterface
     */
    private $queueRepository;

    /**
     * @var ProductQueueItemFactory
     */
    private $queueItemFactory;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param ProductQueueItemRepositoryInterface $queueRepository
     * @param ProductQueueItemFactory $queueItemFactory
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        ProductQueueItemRepositoryInterface $queueRepository,
        ProductQueueItemFactory $queueItemFactory,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->queueRepository = $queueRepository;
        $this->queueItemFactory = $queueItemFactory;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function productChanged($productId, $storeId, array $changedAttributeCodes = null)
    {
        if (!$this->config->isEnabled($storeId) || !$this->config->isRealtimeSyncEnabled($storeId)) {
            return;
        }

        $watched = $this->getWatchedAttributeCodes($storeId);

        if ($changedAttributeCodes !== null && empty(array_intersect($changedAttributeCodes, $watched))) {
            // Caller told us specifically what changed, and none of it is sent to SoloSearch.
            return;
        }

        $this->enqueue($productId, $storeId, ProductQueueItemInterface::OPERATION_UPDATE);
    }

    /**
     * @inheritDoc
     */
    public function productDeleted($productId, $storeId)
    {
        if (!$this->config->isEnabled($storeId) || !$this->config->isRealtimeSyncEnabled($storeId)) {
            return;
        }

        $this->enqueue($productId, $storeId, ProductQueueItemInterface::OPERATION_DELETE);
    }

    /**
     * @inheritDoc
     */
    public function getWatchedAttributeCodes($storeId)
    {
        $fieldMappingCodes = array_filter(array_values($this->config->getFieldMapping($storeId)));

        return array_values(array_unique(array_merge(
            ProductFieldsBuilder::STRUCTURAL_ATTRIBUTE_CODES,
            self::EXTRA_WATCHED_ATTRIBUTE_CODES,
            $fieldMappingCodes
        )));
    }

    /**
     * Queues this product+store change. If a still-unprocessed (STATUS_PENDING) row already exists
     * for this exact product+store, that row is reused - its operation is refreshed to whatever
     * just happened (a pending "update" can still turn into a "delete" before it's ever sent) and
     * its attempts counter reset to 0, but it stays the same row/history entry, since nothing was
     * actually sent for it yet. Otherwise a brand new row is inserted - a product that already has
     * STATUS_SUCCESS/STATUS_ERROR history is never reused for a new change, so that history stays
     * intact (see the Api\Data\ProductQueueItemInterface class docblock).
     *
     * Best-effort: a queueing failure is logged and swallowed, never thrown - this must not break
     * the caller's actual product save/delete, which already succeeded by the time this runs.
     *
     * @param int $productId
     * @param int $storeId
     * @param string $operation
     * @return void
     */
    private function enqueue($productId, $storeId, $operation)
    {
        try {
            $item = $this->queueRepository->findPendingItem($productId, $storeId);

            if ($item === null) {
                $item = $this->queueItemFactory->create();
                $item->setProductId($productId);
                $item->setStoreId($storeId);
                $item->setStatus(ProductQueueItemInterface::STATUS_PENDING);
            }

            $item->setOperation($operation);
            $item->setAttempts(0);
            $item->setResult(null);

            $this->queueRepository->save($item);

            $this->logger->info(sprintf(
                __METHOD__ . ': queued product %s (store %s) for %s',
                $productId,
                $storeId,
                $operation
            ));
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                __METHOD__ . ': failed to queue product %s (store %s, operation %s): %s',
                $productId,
                $storeId,
                $operation,
                $e->getMessage()
            ));
        }
    }
}
