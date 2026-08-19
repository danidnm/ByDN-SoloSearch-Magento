<?php

namespace Bydn\SoloSearch\Cron;

class CleanProductQueue
{
    /**
     * @var \Bydn\SoloSearch\Model\ProductQueueCleaner
     */
    private $productQueueCleaner;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Bydn\SoloSearch\Model\ProductQueueCleaner $productQueueCleaner
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Bydn\SoloSearch\Model\ProductQueueCleaner $productQueueCleaner,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->productQueueCleaner = $productQueueCleaner;
        $this->logger = $logger;
    }

    /**
     * Removes finished product queue history rows past the configured retention window
     *
     * @return void
     */
    public function execute()
    {
        $this->logger->info(__METHOD__ . ': start');

        $this->productQueueCleaner->cleanup();

        $this->logger->info(__METHOD__ . ': end');
    }
}
