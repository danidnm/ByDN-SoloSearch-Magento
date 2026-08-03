<?php

namespace Bydn\SoloSearch\Cron;

class GenerateFeed
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Bydn\SoloSearch\Model\FeedGenerator
     */
    private $feedGenerator;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Bydn\SoloSearch\Model\FeedGenerator $feedGenerator
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Bydn\SoloSearch\Model\FeedGenerator $feedGenerator
    ) {
        $this->logger = $logger;
        $this->feedGenerator = $feedGenerator;
    }

    /**
     * Runs the scheduled feed generation for every store view with daily generation enabled
     *
     * @return void
     */
    public function execute()
    {
        $this->logger->info(__METHOD__ . ': start');

        $this->feedGenerator->generateForScheduledStores();

        $this->logger->info(__METHOD__ . ': end');
    }
}
