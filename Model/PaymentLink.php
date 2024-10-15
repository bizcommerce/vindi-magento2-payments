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

namespace Vindi\VP\Model;

use Vindi\VP\Api\Data\PaymentLinkInterface;
use Magento\Framework\Model\AbstractModel;

class PaymentLink extends AbstractModel implements PaymentLinkInterface
{
    const CACHE_TAG = 'vindi_vp_payment_link';

    /**
     * @var string
     */
    protected $_cacheTag = 'vindi_vp_payment_link';

    /**
     * @var string
     */
    protected $_eventPrefix = 'vindi_vp_payment_link';

    /**
     * Initialize resource model.
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\PaymentLink::class);
    }

    /**
     * @ingeritdoc
     */
    public function getEntityId()
    {
        return $this->getData(self::ENTITY_ID);
    }

    /**
     * @ingeritdoc
     */
    public function setEntityId($entityId)
    {
        $this->setData(self::ENTITY_ID, $entityId);
    }

    /**
     * @ingeritdoc
     */
    public function getLink()
    {
        return $this->getData(self::LINK);
    }

    /**
     * @ingeritdoc
     */
    public function setLink(string $link)
    {
        $this->setData(self::LINK, $link);
    }

    /**
     * @ingeritdoc
     */
    public function getOrderId()
    {
        return $this->getData(self::ORDER_ID);
    }

    /**
     * @ingeritdoc
     */
    public function setOrderId(int $orderId)
    {
        $this->setData(self::ORDER_ID, $orderId);
    }

    /**
     * @ingeritdoc
     */
    public function getCreatedAt()
    {
        return $this->getData(self::CREATED_AT);
    }

    /**
     * @ingeritdoc
     */
    public function setCreatedAt($createdAt)
    {
        $this->setData(self::CREATED_AT, $createdAt);
    }

    /**
     * @ingeritdoc
     */
    public function getVindiPaymentMethod()
    {
        return $this->getData(self::VINDI_PAYMENT_METHOD);
    }

    /**
     * @ingeritdoc
     */
    public function setVindiPaymentMethod($vindiPaymentMethod)
    {
        $this->setData(self::VINDI_PAYMENT_METHOD, $vindiPaymentMethod);
    }

    /**
     * @ingeritdoc
     */
    public function getCustomerId()
    {
        return $this->getData(self::CUSTOMER_ID);
    }

    /**
     * @ingeritdoc
     */
    public function setCustomerId($customerId)
    {
        $this->setData(self::CUSTOMER_ID, $customerId);
    }

    /**
     * @ingeritdoc
     */
    public function getStatus()
    {
        return $this->getData(self::STATUS);
    }

    /**
     * @ingeritdoc
     */
    public function setStatus(string $status)
    {
        $this->setData(self::STATUS, $status);
    }
}

