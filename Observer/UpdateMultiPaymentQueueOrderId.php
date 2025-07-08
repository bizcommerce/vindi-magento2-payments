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

namespace Vindi\VP\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Vindi\VP\Model\MultiPaymentQueueService;
use Psr\Log\LoggerInterface;

/**
 * Class UpdateMultiPaymentQueueOrderId
 * Updates order_id in multi-payment queue records after order is saved
 */
class UpdateMultiPaymentQueueOrderId implements ObserverInterface
{
    /**
     * @var MultiPaymentQueueService
     */
    private $multiPaymentQueueService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param MultiPaymentQueueService $multiPaymentQueueService
     * @param LoggerInterface $logger
     */
    public function __construct(
        MultiPaymentQueueService $multiPaymentQueueService,
        LoggerInterface $logger
    ) {
        $this->multiPaymentQueueService = $multiPaymentQueueService;
        $this->logger = $logger;
    }

    /**
     * Update order_id in multi-payment queue records after order is saved
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();

        $this->logger->info("UpdateMultiPaymentQueueOrderId - Observer triggered for order: " . ($order ? $order->getIncrementId() : 'NULL'));

        if (!$order || !$order->getId()) {
            $this->logger->info("UpdateMultiPaymentQueueOrderId - No valid order found or order not saved yet");
            return;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            $this->logger->info("UpdateMultiPaymentQueueOrderId - No payment found for order: " . $order->getIncrementId());
            return;
        }

        $paymentMethod = $payment->getMethod();
        
        if (!in_array($paymentMethod, ['vindi_vp_cardpix', 'vindi_vp_cardcard', 'vindi_vp_cardbankslippix'])) {
            $this->logger->info("UpdateMultiPaymentQueueOrderId - Not a multi-payment method: {$paymentMethod}");
            return;
        }

        try {
            $queueItems = $this->multiPaymentQueueService->getByIncrementId($order->getIncrementId());
            
            if (empty($queueItems)) {
                $this->logger->info("UpdateMultiPaymentQueueOrderId - No queue records found for order: " . $order->getIncrementId());
                return;
            }

            foreach ($queueItems as $queueItem) {
                if ($queueItem->getOrderId() === null || $queueItem->getOrderId() == 0) {
                    $queueItem->setOrderId((int)$order->getId());
                    $this->multiPaymentQueueService->save($queueItem);
                    
                    $this->logger->info(
                        "UpdateMultiPaymentQueueOrderId - Updated order_id for queue record",
                        [
                            'queue_id' => $queueItem->getId(),
                            'increment_id' => $order->getIncrementId(),
                            'order_id' => $order->getId(),
                            'secondary_method_type' => $queueItem->getSecondaryMethodType()
                        ]
                    );
                }
            }

        } catch (\Exception $e) {
            $this->logger->error(
                "UpdateMultiPaymentQueueOrderId - Failed to update order_id for order {$order->getIncrementId()}: {$e->getMessage()}",
                [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'error' => $e->getMessage()
                ]
            );
        }
    }
}
