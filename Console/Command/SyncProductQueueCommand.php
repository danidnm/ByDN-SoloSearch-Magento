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
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Bydn\SoloSearch\Helper\Config
     */
    private $config;

    /**
     * @var \Bydn\SoloSearch\Model\ProductQueueSync
     */
    private $productQueueSync;

    /**
     * @param \Magento\Framework\App\State $appState
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Bydn\SoloSearch\Helper\Config $config
     * @param \Bydn\SoloSearch\Model\ProductQueueSync $productQueueSync
     */
    public function __construct(
        \Magento\Framework\App\State $appState,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Psr\Log\LoggerInterface $logger,
        \Bydn\SoloSearch\Helper\Config $config,
        \Bydn\SoloSearch\Model\ProductQueueSync $productQueueSync
    ) {
        parent::__construct();
        $this->appState = $appState;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->config = $config;
        $this->productQueueSync = $productQueueSync;
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
                'Store view code or ID. If omitted, syncs every enabled store view.'
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
            $output->writeln('Product queue sync finished for all enabled store views.');
            $this->logger->info(__METHOD__ . ': end');

            return \Symfony\Component\Console\Command\Command::SUCCESS;
        }

        try {
            $store = $this->storeManager->getStore($storeOption);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $output->writeln("<error>Store not found: {$storeOption}</error>");
            $this->logger->info(__METHOD__ . ": store {$storeOption} not found");
            $this->logger->info(__METHOD__ . ': end');

            return \Symfony\Component\Console\Command\Command::FAILURE;
        }

        if (!$this->config->isEnabled($store->getId())) {
            $output->writeln("SoloSearch is disabled for store '{$store->getCode()}'.");
            $this->logger->info(__METHOD__ . ": store {$store->getId()} disabled, skipping");
            $this->logger->info(__METHOD__ . ': end');

            return \Symfony\Component\Console\Command\Command::FAILURE;
        }

        // syncStoreIfEnabled(), not syncStore() directly, so this stays correct even if the
        // config check above and the sync itself ever race (e.g. disabled between the two) - the
        // isEnabled() check above is only here for this command's own CLI messaging,
        // syncStoreIfEnabled() repeats it internally regardless.
        $this->productQueueSync->syncStoreIfEnabled((int) $store->getId());

        $output->writeln("Product queue sync finished for store '{$store->getCode()}'.");
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
