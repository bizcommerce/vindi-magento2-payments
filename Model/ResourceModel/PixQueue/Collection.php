<?php
namespace Vindi\VP\Model\ResourceModel\PixQueue;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(\Vindi\VP\Model\PixQueue::class, \Vindi\VP\Model\ResourceModel\PixQueue::class);
    }
}
