<?php

/**
 * Vindi
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 * @copyright   Copyright (c) Vindi
 *
 */

namespace Vindi\VP\Block\Info;

use Magento\Framework\DataObject;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Model\Config;

class CreditCard extends AbstractInfo
{
    protected $_template = 'Vindi_VP::payment/info/cc.phtml';

    /**
     * @var PriceCurrencyInterface
     */
    protected $priceCurrency;

    /**
     * CreditCard constructor.
     * @param Context $context
     * @param ConfigInterface $config
     * @param Config $paymentConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        ConfigInterface $config,
        Config $paymentConfig,
        PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $config, $paymentConfig, $data);
        $this->priceCurrency = $priceCurrency;
    }

    /**
     * Prepare specific information to display on payment info block
     *
     * @param \Magento\Framework\DataObject|array|null $transport
     * @return \Magento\Framework\DataObject
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $info = $this->getInfo();
        $additionalData = $info->getAdditionalInformation('additional_data');

        $installments = $info->getAdditionalInformation('installments')
            ?? (is_array($additionalData) && isset($additionalData['installments'])
                ? (int) $additionalData['installments']
                : 1);

        $status = $info->getAdditionalInformation('status_name')
            ?? (is_array($additionalData) && isset($additionalData['status_name'])
                ? $additionalData['status_name']
                : __('N/A'));

        /** @var \Magento\Sales\Model\Order $order */
        $order = $info->getOrder();
        $installmentValue = $order->getGrandTotal() / max($installments, 1);

        $body = [
            (string) __('Credit Card Type') => $this->getCcTypeName(),
            (string) __('Credit Card Owner') => $info->getCcOwner(),
            (string) __('Card Number') => sprintf('xxxx-%s', $info->getCcLast4()),
            (string) __('Installments') => sprintf(
                '%s x of %s',
                $installments,
                $this->priceCurrency->format($installmentValue, false)
            ),
            (string) __('Status') => $status,
        ];

        return new \Magento\Framework\DataObject($body);
    }

    /**
     * Retrieve credit card type name
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCcTypeName()
    {
        $types = $this->paymentConfig->getCcTypes();
        $ccType = $this->getInfo()->getCcType();
        if (isset($types[$ccType])) {
            return $types[$ccType];
        }
        return empty($ccType) ? __('N/A') : __(ucwords($ccType));
    }

}
