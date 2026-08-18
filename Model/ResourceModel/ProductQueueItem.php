<?php

namespace Bydn\SoloSearch\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductQueueItem extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('bydn_solosearch_product_queue', 'entity_id');
    }
}
