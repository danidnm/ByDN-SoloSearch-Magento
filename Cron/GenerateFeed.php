<?php

namespace Bydn\SoloSearch\Cron;

class GenerateFeed
{
    /**
     * @var \Bydn\SoloSearch\Model\FeedGenerator
     */
    private $feedGenerator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Bydn\SoloSearch\Model\FeedGenerator $feedGenerator
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Bydn\SoloSearch\Model\FeedGenerator $feedGenerator,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->feedGenerator = $feedGenerator;
        $this->logger = $logger;
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
