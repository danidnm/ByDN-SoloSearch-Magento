<?php

namespace Bydn\SoloSearch\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;

class FeedPath extends \Magento\Framework\App\Config\Value
{
    /**
     * @var \Bydn\SoloSearch\Model\FeedPathValidator
     */
    private $feedPathValidator;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param \Bydn\SoloSearch\Model\FeedPathValidator $feedPathValidator
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Bydn\SoloSearch\Model\FeedPathValidator $feedPathValidator,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->feedPathValidator = $feedPathValidator;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Rejects absolute paths and ".." traversal segments before the value is persisted, so the
     * admin gets immediate feedback instead of the feed silently falling back to the default path
     * at generation time (see Helper\Config::getFeedPath()).
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = (string) $this->getValue();

        if ($value !== '' && !$this->feedPathValidator->isValid($value)) {
            throw new LocalizedException(
                __('Feed File Path must be a relative path (no leading "/") and must not contain ".." segments.')
            );
        }

        return parent::beforeSave();
    }
}
