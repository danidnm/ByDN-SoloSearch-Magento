<?php

namespace Bydn\SoloSearch\Model;

use Bydn\SoloSearch\Api\Data\ProductQueueItemInterface;
use Bydn\SoloSearch\Api\ProductQueueItemRepositoryInterface;
use Bydn\SoloSearch\Model\ResourceModel\ProductQueueItem as ResourceModel;
use Bydn\SoloSearch\Model\ResourceModel\ProductQueueItem\CollectionFactory;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class ProductQueueItemRepository implements ProductQueueItemRepositoryInterface
{
    /**
     * @var ResourceModel
     */
    private $resource;

    /**
     * @var ProductQueueItemFactory
     */
    private $itemFactory;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @param ResourceModel $resource
     * @param ProductQueueItemFactory $itemFactory
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        ResourceModel $resource,
        ProductQueueItemFactory $itemFactory,
        CollectionFactory $collectionFactory
    ) {
        $this->resource = $resource;
        $this->itemFactory = $itemFactory;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * @inheritDoc
     */
    public function save(ProductQueueItemInterface $item)
    {
        try {
            $this->resource->save($item);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the queue item: %1', $e->getMessage()), $e);
        }

        return $item;
    }

    /**
     * @inheritDoc
     */
    public function getById($entityId)
    {
        $item = $this->itemFactory->create();
        $this->resource->load($item, $entityId);

        if (!$item->getEntityId()) {
            throw new NoSuchEntityException(__('Queue item with id "%1" does not exist.', $entityId));
        }

        return $item;
    }

    /**
     * @inheritDoc
     */
    public function getByProductAndStore($productId, $storeId)
    {
        /** @var \Bydn\SoloSearch\Model\ResourceModel\ProductQueueItem\Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(ProductQueueItemInterface::PRODUCT_ID, $productId)
            ->addFieldToFilter(ProductQueueItemInterface::STORE_ID, $storeId)
            ->setPageSize(1);

        $item = $collection->getFirstItem();

        return $item->getEntityId() ? $item : null;
    }

    /**
     * @inheritDoc
     */
    public function getPendingItems($storeId, $limit)
    {
        /** @var \Bydn\SoloSearch\Model\ResourceModel\ProductQueueItem\Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(ProductQueueItemInterface::STORE_ID, $storeId)
            ->addFieldToFilter(ProductQueueItemInterface::STATUS, ProductQueueItemInterface::STATUS_PENDING)
            ->setOrder(ProductQueueItemInterface::CREATED_AT, DataCollection::SORT_ORDER_ASC)
            ->setPageSize($limit);

        return $collection->getItems();
    }

    /**
     * @inheritDoc
     */
    public function delete(ProductQueueItemInterface $item)
    {
        try {
            $this->resource->delete($item);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete the queue item: %1', $e->getMessage()), $e);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteById($entityId)
    {
        return $this->delete($this->getById($entityId));
    }
}
