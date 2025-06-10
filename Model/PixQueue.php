<?php
namespace Vindi\VP\Model;

use Magento\Framework\Model\AbstractModel;

class PixQueue extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Vindi\VP\Model\ResourceModel\PixQueue::class);
    }
}
