<?php

/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to a newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Gateway\Request\BankSlip;

use Vindi\VP\Gateway\Request\PaymentsRequest;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;

class TransactionRequest extends PaymentsRequest implements BuilderInterface
{
    /**
     *
     * @param array $buildSubject
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function build(array $buildSubject)
    {
        if (
            !isset($buildSubject['payment']) ||
            !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $buildSubject['payment']->getPayment();
        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();

        $request = $this->getTransaction($order, $buildSubject['amount']);
        $request['payment'] = $this->getPaymentMethod($order);

        return [
            'request' => $request,
            'client_config' => [
                'store_id' => $order->getStoreId()
            ]
        ];
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @return array
     */
    protected function getPaymentMethod($order)
    {
        return [
            'payment_method_id' => $this->helper->getMethodId('BANKSLIP')
        ];
    }
}
