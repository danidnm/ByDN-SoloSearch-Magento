<?php

namespace Bydn\SoloSearch\Cron;

class SyncProductQueue
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Bydn\SoloSearch\Model\ProductQueueSync
     */
    private $productQueueSync;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Bydn\SoloSearch\Model\ProductQueueSync $productQueueSync
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Bydn\SoloSearch\Model\ProductQueueSync $productQueueSync
    ) {
        $this->logger = $logger;
        $this->productQueueSync = $productQueueSync;
    }

    /**
     * Runs the real-time product sync for every store view with pending changes
     *
     * @return void
     */
    public function execute()
    {
        $this->logger->info(__METHOD__ . ': start');

        $this->productQueueSync->syncAllStores();

        $this->logger->info(__METHOD__ . ': end');
    }
}
