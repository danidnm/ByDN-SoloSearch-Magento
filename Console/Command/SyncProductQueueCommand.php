<?php

namespace Bydn\SoloSearch\Console\Command;

class SyncProductQueueCommand extends \Symfony\Component\Console\Command\Command
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
     * @var \Bydn\SoloSearch\Model\ProductQueueSync
     */
    private $productQueueSync;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Magento\Framework\App\State $appState
     * @param \Bydn\SoloSearch\Helper\Config $config
     * @param \Bydn\SoloSearch\Model\ProductQueueSync $productQueueSync
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\State $appState,
        \Bydn\SoloSearch\Helper\Config $config,
        \Bydn\SoloSearch\Model\ProductQueueSync $productQueueSync,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct();
        $this->appState = $appState;
        $this->config = $config;
        $this->productQueueSync = $productQueueSync;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('solosearch:product-queue:sync');
        $this->setDescription('Sends pending product changes to SoloSearch, without waiting for a full Feed Reindex');
        $this->setDefinition([
            new \Symfony\Component\Console\Input\InputOption(
                self::PARAM_STORE,
                null,
                \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL,
                'Store view ID (not code). If omitted, syncs every enabled store view.'
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
            $this->productQueueSync->syncAllStores();
        }
        else {
            $this->productQueueSync->syncStoreIfEnabled($storeOption);
        }

        $output->writeln('Product queue sync finished. See var/log/solosearch.log for more information.');
        $this->logger->info(__METHOD__ . ': end');

        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }

    /**
     * Sets the crontab area code, unless it is already set - see GenerateFeedCommand for why.
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
