<?php

namespace Bydn\SoloSearch\Observer;

use Bydn\SoloSearch\Api\ProductChangeNotifierInterface;
use Bydn\SoloSearch\Model\FeedGenerator;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Deliberately thin - extracts what changed from the Product model (the only place that
 * information is available) and hands off to ProductChangeNotifier, which owns every decision
 * about whether it's relevant and what to do about it. The one exception is status/visibility:
 * whether that means an update or a removal from the index depends on the product's current
 * eligibility (FeedGenerator::isProductVisibleInFeed()), which only this observer can evaluate -
 * it's the only place with the loaded Product object.
 */
class ProductSaveAfter implements ObserverInterface
{
    /**
     * @var ProductChangeNotifierInterface
     */
    private $notifier;

    /**
     * @var FeedGenerator
     */
    private $feedGenerator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param ProductChangeNotifierInterface $notifier
     * @param FeedGenerator $feedGenerator
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        ProductChangeNotifierInterface $notifier,
        FeedGenerator $feedGenerator,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->notifier = $notifier;
        $this->feedGenerator = $feedGenerator;
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

            // A save in the admin's "Default/All Store Views" scope reports store_id 0, which has
            // no SoloSearch config of its own - fall back to every store view the product is
            // actually assigned to, same as FeedGenerator::generateForAllStores() only ever
            // iterates real store views, never store 0.
            $eventStoreId = (int) $product->getStoreId();
            $storeIds = $eventStoreId > 0 ? [$eventStoreId] : array_map('intval', (array) $product->getStoreIds());

            foreach ($storeIds as $storeId) {
                $changedCodes = [];

                foreach ($this->notifier->getWatchedAttributeCodes($storeId) as $code) {
                    if ($product->dataHasChangedFor($code)) {
                        $changedCodes[] = $code;
                    }
                }

                if (empty($changedCodes)) {
                    continue;
                }

                $touchesEligibility = in_array('status', $changedCodes, true)
                    || in_array('visibility', $changedCodes, true);

                if ($touchesEligibility && !$this->feedGenerator->isProductVisibleInFeed($product)) {
                    // No longer enabled and/or visible in search - remove it instead of sending
                    // stale data as an "update". Whatever else changed in this same save is moot,
                    // the product shouldn't be in the index at all right now.
                    $this->notifier->productDeleted((int) $product->getId(), $storeId);

                    continue;
                }

                $this->notifier->productChanged((int) $product->getId(), $storeId, $changedCodes);
            }
        } catch (\Exception $e) {
            $this->logger->error('ProductSaveAfter observer failed: ' . $e->getMessage());
        }
    }
}
