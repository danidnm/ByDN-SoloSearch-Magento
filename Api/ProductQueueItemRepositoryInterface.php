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
     * Finds the queue row for a given product+store, if one already exists (pending or error).
     * Returns null instead of throwing - this is a lookup by natural key ('does a row already
     * exist for this product on this store'), not a hard "must exist" like getById().
     *
     * @param int $productId
     * @param int $storeId
     * @return ProductQueueItemInterface|null
     */
    public function getByProductAndStore($productId, $storeId);

    /**
     * Oldest-first, up to $limit pending items - what the sync cron pulls per batch run.
     *
     * @param int $limit
     * @return ProductQueueItemInterface[]
     */
    public function getPendingItems($limit);

    /**
     * @param ProductQueueItemInterface $item
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(ProductQueueItemInterface $item);

    /**
     * @param int $entityId
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function deleteById($entityId);
}
