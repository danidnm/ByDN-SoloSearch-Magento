<?php

namespace Bydn\SoloSearch\Observer;

use Bydn\SoloSearch\Api\ProductChangeNotifierInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Handles the admin grid's "Update Attributes" mass action - a genuinely different code path from
 * a normal product save (see Magento\Catalog\Model\Product\Action::updateAttributes()): it applies
 * a single bulk SQL UPDATE across every selected product, never loading or saving individual
 * Product models, so catalog_product_save_after never fires for it and there's no Product object
 * to diff via dataHasChangedFor(). catalog_product_attribute_update_before is the only signal -
 * fired once per mass action, not once per product, already carrying exactly which attributes are
 * changing (no diffing needed) and every affected product id.
 */
class ProductAttributeMassUpdate implements ObserverInterface
{
    /**
     * @var ProductChangeNotifierInterface
     */
    private $notifier;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param ProductChangeNotifierInterface $notifier
     * @param StoreManagerInterface $storeManager
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        ProductChangeNotifierInterface $notifier,
        StoreManagerInterface $storeManager,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->notifier = $notifier;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $event = $observer->getEvent();
            $productIds = (array) $event->getProductIds();
            $changedCodes = array_keys((array) $event->getAttributesData());

            if (empty($productIds) || empty($changedCodes)) {
                return;
            }

            // store_id 0 means "All Store Views" was selected for the mass action - unlike a
            // regular product save, there's no Product object here to ask which store views it
            // actually belongs to, so this falls back to every configured store rather than a
            // precise per-product resolution. Queuing a product for a store it isn't really
            // assigned to is harmless (the sync step will just find nothing meaningfully
            // different to send); missing a store it IS assigned to would not be.
            $eventStoreId = (int) $event->getStoreId();
            $storeIds = $eventStoreId > 0 ? [$eventStoreId] : $this->getAllStoreIds();

            foreach ($productIds as $productId) {
                foreach ($storeIds as $storeId) {
                    $this->notifier->productChanged((int) $productId, $storeId, $changedCodes);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('ProductAttributeMassUpdate observer failed: ' . $e->getMessage());
        }
    }

    /**
     * @return int[]
     */
    private function getAllStoreIds()
    {
        $storeIds = [];

        foreach ($this->storeManager->getStores() as $store) {
            $storeIds[] = (int) $store->getId();
        }

        return $storeIds;
    }
}
