<?php

namespace Bydn\SoloSearch\Observer;

use Bydn\SoloSearch\Api\ProductChangeNotifierInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ProductDeleteAfter implements ObserverInterface
{
    /**
     * @var ProductChangeNotifierInterface
     */
    private $notifier;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param ProductChangeNotifierInterface $notifier
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        ProductChangeNotifierInterface $notifier,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->notifier = $notifier;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $observer->getEvent()->getProduct();

            // Same store-resolution reasoning as ProductSaveAfter - store 0 isn't a real store.
            $eventStoreId = (int) $product->getStoreId();
            $storeIds = $eventStoreId > 0 ? [$eventStoreId] : array_map('intval', (array) $product->getStoreIds());

            foreach ($storeIds as $storeId) {
                $this->notifier->productDeleted((int) $product->getId(), $storeId);
            }
        } catch (\Exception $e) {
            $this->logger->error('ProductDeleteAfter observer failed: ' . $e->getMessage());
        }
    }
}
