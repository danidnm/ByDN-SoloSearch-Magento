<?php

namespace Bydn\SoloSearch\Api;

use Bydn\SoloSearch\Api\Data\ProductQueueItemInterface;

interface ProductQueueItemRepositoryInterface
{
    /**
     * @param ProductQueueItemInterface $item
     * @return ProductQueueItemInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(ProductQueueItemInterface $item);

    /**
     * @param int $entityId
     * @return ProductQueueItemInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById($entityId);

    /**
     * Finds the still-unprocessed (STATUS_PENDING) queue row for a given product+store, if one
     * exists. Deliberately ignores STATUS_SUCCESS/STATUS_ERROR rows - those are history, never
     * reused for a new change (see ProductChangeNotifier::enqueue()). Returns null instead of
     * throwing - this is a lookup by natural key ('is there already unsent work queued for this
     * product on this store'), not a hard "must exist" like getById().
     *
     * @param int $productId
     * @param int $storeId
     * @return ProductQueueItemInterface|null
     */
    public function findPendingItem($productId, $storeId);

    /**
     * Oldest-first, up to $limit pending items for a single store - what the sync cron pulls per
     * batch run. Scoped to one store because each store view syncs to its own SoloSearch search
     * engine (different api_token/search_engine_id), so a batch can never mix stores.
     *
     * @param int $storeId
     * @param int $limit
     * @return ProductQueueItemInterface[]
     */
    public function getPendingItems($storeId, $limit);

    /**
     * @param ProductQueueItemInterface $item
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(ProductQueueItemInterface $item);

    /**
     * Removes finished (STATUS_SUCCESS/STATUS_ERROR) rows last updated before $before - used by
     * the history retention cleanup (see Model\ProductQueueCleaner). Deliberately never touches
     * STATUS_PENDING rows regardless of age - those are real unsent work, not history.
     *
     * @param \DateTimeInterface $before
     * @return int Number of rows removed
     */
    public function deleteFinishedOlderThan(\DateTimeInterface $before);

    /**
     * @param int $entityId
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function deleteById($entityId);
}
