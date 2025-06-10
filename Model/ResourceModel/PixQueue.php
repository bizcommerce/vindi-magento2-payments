<?php
namespace Vindi\VP\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PixQueue extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('vindi_vp_pix_queue', 'entity_id');
    }
}
