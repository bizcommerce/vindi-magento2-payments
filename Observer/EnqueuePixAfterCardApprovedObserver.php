<?php
namespace Vindi\VP\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Vindi\VP\Model\PixQueueFactory;
use Magento\Sales\Model\Order;

class EnqueuePixAfterCardApprovedObserver implements ObserverInterface
{
    protected $pixQueueFactory;

    public function __construct(
        PixQueueFactory $pixQueueFactory
    ) {
        $this->pixQueueFactory = $pixQueueFactory;
    }

    public function execute(Observer $observer)
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            return;
        }
        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }
        $method = $payment->getMethod();
        if ($method !== 'cardpix') {
            return;
        }
        // Recupera dados do Pix do payment info
        $amountPix = (float)($payment->getAdditionalInformation('amount_pix') ?? 0);
        $pixMeta = $payment->getAdditionalInformation('pix_meta') ?? [];
        $paymentIdCc = $payment->getLastTransId() ?: $payment->getTransactionId();
        if ($amountPix <= 0 || !$paymentIdCc) {
            return;
        }
        $pixQueue = $this->pixQueueFactory->create();
        $pixQueue->setData([
            'order_id' => $order->getId(),
            'payment_id_cc' => $paymentIdCc,
            'amount_pix' => (int)($amountPix * 100),
            'payment_meta' => json_encode($pixMeta),
            'status' => 'pending',
            'attempts' => 0
        ]);
        $pixQueue->save();
    }
}
