<?php

namespace Bydn\SoloSearch\Console\Command;

class GenerateFeedCommand extends \Symfony\Component\Console\Command\Command
{
    const PARAM_STORE = 'store';

    /**
     * @var \Magento\Framework\App\State
     */
    private $appState;

    /**
     * @var \Bydn\SoloSearch\Helper\Config
     */
    private $config;

    /**
     * @var \Bydn\SoloSearch\Model\FeedGenerator
     */
    private $feedGenerator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Magento\Framework\App\State $appState
     * @param \Bydn\SoloSearch\Helper\Config $config
     * @param \Bydn\SoloSearch\Model\FeedGenerator $feedGenerator
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\State $appState,
        \Bydn\SoloSearch\Helper\Config $config,
        \Bydn\SoloSearch\Model\FeedGenerator $feedGenerator,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct();
        $this->appState = $appState;
        $this->config = $config;
        $this->feedGenerator = $feedGenerator;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('solosearch:feed:generate');
        $this->setDescription('Generates the SoloSearch product feed');
        $this->setDefinition([
            new \Symfony\Component\Console\Input\InputOption(
                self::PARAM_STORE,
                null,
                \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL,
                'Store view ID (not code). If omitted, generates the feed for every enabled store view.'
            ),
        ]);
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output)
    {
        $this->logger->info(__METHOD__ . ': start');

        $this->ensureAreaCode();

        $storeOption = $input->getOption(self::PARAM_STORE);

        if ($storeOption === null) {
            $this->feedGenerator->generateForAllStores();
        }
        else {
            $this->feedGenerator->generateForStoreIfEnabled($storeOption);
        }

        $output->writeln('Feed generation finished. See var/log/solosearch.log for more information.');
        $this->logger->info(__METHOD__ . ': end');

        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }

    /**
     * Sets the crontab area code, unless it is already set - e.g. when this command runs as part
     * of a batch process that iterates several stores within the same PHP process instead of one
     * bin/magento invocation per store, only the first call may set it; State::setAreaCode() throws
     * "Area code is already set" on every call after that, even though the value would be the same.
     *
     * @return void
     */
    private function ensureAreaCode()
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_CRONTAB);
        }
    }
}
