<?php

namespace Bydn\SoloSearch\Model;

use Bydn\SoloSearch\Api\ProductQueueItemRepositoryInterface;
use Bydn\SoloSearch\Helper\Config;

class ProductQueueCleaner
{
    /**
     * @var ProductQueueItemRepositoryInterface
     */
    private $queueRepository;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param ProductQueueItemRepositoryInterface $queueRepository
     * @param Config $config
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        ProductQueueItemRepositoryInterface $queueRepository,
        Config $config,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->queueRepository = $queueRepository;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Removes finished (STATUS_SUCCESS/STATUS_ERROR) product queue rows older than the configured
     * retention window (see Config::getProductQueueRetentionDays()). Pending rows are never touched.
     *
     * @return void
     */
    public function cleanup()
    {
        $retentionDays = $this->config->getProductQueueRetentionDays();
        $before = new \DateTime("-{$retentionDays} days");

        $deleted = $this->queueRepository->deleteFinishedOlderThan($before);

        $this->logger->info(sprintf(
            'ProductQueueCleaner: removed %d finished product queue entr%s older than %d day(s)',
            $deleted,
            $deleted === 1 ? 'y' : 'ies',
            $retentionDays
        ));
    }
}
