<?php

namespace Bydn\SoloSearch\Model\ResourceModel\ProductQueueItem;

use Bydn\SoloSearch\Model\ProductQueueItem as Model;
use Bydn\SoloSearch\Model\ResourceModel\ProductQueueItem as ResourceModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
