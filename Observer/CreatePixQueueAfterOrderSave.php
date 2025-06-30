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
use Vindi\VP\Model\MultiPaymentQueue;
use Psr\Log\LoggerInterface;

/**
 * Class CreatePixQueueAfterOrderSave
 * Creates PIX queue record after order is saved for CardPix payments
 */
class CreatePixQueueAfterOrderSave implements ObserverInterface
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
     * Create PIX queue record after order save for CardPix payments
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        
        if (!$order || !$order->getId()) {
            return;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }

        // Check if this is a CardPix payment with queue data
        $pixQueueData = $payment->getAdditionalInformation('pix_queue_data');
        if (!$pixQueueData || !is_array($pixQueueData)) {
            return;
        }

        // Check if payment method is CardPix
        if ($payment->getMethod() !== 'vindi_vp_cardpix') {
            return;
        }

        try {
            // Create the PIX queue record now that we have the order ID
            $this->multiPaymentQueueService->addToQueue(
                (int)$order->getId(),
                $pixQueueData['increment_id'],
                $pixQueueData['payment_method'],
                '', // Primary transaction ID will be set later when card response comes
                $pixQueueData['secondary_method_type'],
                $pixQueueData['secondary_amount'],
                $pixQueueData['request_data'],
                $pixQueueData['status']
            );

            // Remove the temporary data from payment additional information
            $payment->unsAdditionalInformation('pix_queue_data');
            $payment->save();

            $this->logger->info(
                "CardPix - PIX queue record created after order save for order {$order->getIncrementId()}",
                [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'amount' => $pixQueueData['secondary_amount']
                ]
            );

        } catch (\Exception $e) {
            $this->logger->error(
                "CardPix - Failed to create PIX queue record for order {$order->getIncrementId()}: {$e->getMessage()}",
                [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'error' => $e->getMessage()
                ]
            );
        }
    }
}
