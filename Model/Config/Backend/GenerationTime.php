<?php

namespace Bydn\SoloSearch\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;

class GenerationTime extends \Magento\Framework\App\Config\Value
{
    // Cron path referenced by the "config_path" of the bydn_solosearch_generate_feed job in crontab.xml
    const CRON_STRING_PATH = 'crontab/bydn_solosearch/jobs/bydn_solosearch_generate_feed/schedule/cron_expr';

    /**
     * @var \Magento\Framework\App\Config\ValueFactory
     */
    private $configValueFactory;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param \Magento\Framework\App\Config\ValueFactory $configValueFactory
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\App\Config\ValueFactory $configValueFactory,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->configValueFactory = $configValueFactory;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Converts the saved time value ("HH,MM,SS", as stored by the admin time picker) into a
     * daily cron expression and writes it to the crontab config path used by crontab.xml.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function afterSave()
    {
        $time = explode(',', (string) $this->getValue());
        $hour = (int) ($time[0] ?? 3);
        $minute = (int) ($time[1] ?? 0);

        $cronExprString = sprintf('%d %d * * *', $minute, $hour);

        try {
            $this->configValueFactory->create()
                ->load(self::CRON_STRING_PATH, 'path')
                ->setValue($cronExprString)
                ->setPath(self::CRON_STRING_PATH)
                ->save();
        } catch (\Exception $e) {
            throw new LocalizedException(__('We can\'t save the cron expression.'));
        }

        return parent::afterSave();
    }
}
