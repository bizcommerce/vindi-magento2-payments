<?php
declare(strict_types=1);

/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Model\ResourceModel\MultiPaymentQueue;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Vindi\VP\Model\MultiPaymentQueue;
use Vindi\VP\Model\ResourceModel\MultiPaymentQueue as MultiPaymentQueueResource;

/**
 * Class Collection
 * Multi Payment Queue collection
 */
class Collection extends AbstractCollection
{
    /**
     * Initialize collection
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(MultiPaymentQueue::class, MultiPaymentQueueResource::class);
    }

    /**
     * Get pending items
     *
     * @return $this
     */
    public function getPendingItems()
    {
        return $this->addFieldToFilter('status', MultiPaymentQueue::STATUS_PENDING)
            ->addFieldToFilter('next_attempt_at', [
                ['null' => true],
                ['lteq' => new \Zend_Db_Expr('NOW()')]
            ]);
    }

    /**
     * Get items by order ID
     *
     * @param int $orderId
     * @return $this
     */
    public function getByOrderId(int $orderId)
    {
        return $this->addFieldToFilter('order_id', $orderId);
    }

    /**
     * Get items by payment method
     *
     * @param string $paymentMethod
     * @return $this
     */
    public function getByPaymentMethod(string $paymentMethod)
    {
        return $this->addFieldToFilter('payment_method', $paymentMethod);
    }
}
