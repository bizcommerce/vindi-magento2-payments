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
 * Class CreateMultiPaymentQueueAfterOrderSave
 * Creates multi-payment queue records after order is saved for multi-payment methods
 */
class CreateMultiPaymentQueueAfterOrderSave implements ObserverInterface
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
     * Create multi-payment queue records after order save for multi-payment methods
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();

        $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Observer triggered for order: " . ($order ? $order->getIncrementId() : 'NULL'));

        if (!$order || !$order->getId()) {
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - No valid order found");
            return;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - No payment found for order: " . $order->getIncrementId());
            return;
        }

        $paymentMethod = $payment->getMethod();
        $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Payment method: " . $paymentMethod . " for order: " . $order->getIncrementId());

        // Process different multi-payment methods
        switch ($paymentMethod) {
            case 'vindi_vp_cardbankslippix':
                $this->processCardBankSlipPix($order, $payment);
                break;
            case 'vindi_vp_cardpix':
                $this->processCardPix($order, $payment);
                break;
            case 'vindi_vp_cardcard':
                $this->processCardCard($order, $payment);
                break;
            default:
                $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Not a multi-payment method, skipping");
                return;
        }
    }

    /**
     * Process CardBankSlipPix payment queue data
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return void
     */
    private function processCardBankSlipPix($order, $payment): void
    {
        $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Processing CardBankSlipPix payment for order: " . $order->getIncrementId());

        // Process Bolepix queue data if present
        $bolepixQueueData = $payment->getAdditionalInformation('bolepix_queue_data');
        if ($bolepixQueueData && is_array($bolepixQueueData)) {
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Found Bolepix queue data for order: " . $order->getIncrementId());
            $this->processQueueData($order, $payment, $bolepixQueueData, 'Bolepix', 'bolepix_queue_data');
        } else {
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - No Bolepix queue data found for order: " . $order->getIncrementId());
        }
    }

    /**
     * Process CardPix payment queue data
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return void
     */
    private function processCardPix($order, $payment): void
    {
        $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Processing CardPix payment for order: " . $order->getIncrementId());

        // Process PIX queue data if present
        $pixQueueData = $payment->getAdditionalInformation('pix_queue_data');
        if ($pixQueueData && is_array($pixQueueData)) {
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Found PIX queue data for order: " . $order->getIncrementId());
            $this->processQueueData($order, $payment, $pixQueueData, 'PIX', 'pix_queue_data');
        } else {
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - No PIX queue data found for order: " . $order->getIncrementId());
        }
    }

    /**
     * Process CardCard payment queue data
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return void
     */
    private function processCardCard($order, $payment): void
    {
        $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Processing CardCard payment for order: " . $order->getIncrementId());

        // Process Card2 queue data if present
        $card2QueueData = $payment->getAdditionalInformation('card2_queue_data');
        if ($card2QueueData && is_array($card2QueueData)) {
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Found Card2 queue data for order: " . $order->getIncrementId());
            $this->processQueueData($order, $payment, $card2QueueData, 'Card2', 'card2_queue_data');
        } else {
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - No Card2 queue data found for order: " . $order->getIncrementId());
        }
    }

    /**
     * Process queue data and create queue record
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param array $queueData
     * @param string $type
     * @param string $additionalKey
     * @return void
     */
    private function processQueueData($order, $payment, array $queueData, string $type, string $additionalKey): void
    {
        try {
            $orderId = $order->getId();
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Processing {$type} queue data for order: " . $order->getIncrementId() . " with order ID: " . ($orderId ?: 'NULL'));

            if (!$orderId) {
                $this->logger->error("CreateMultiPaymentQueueAfterOrderSave - Order ID is null or 0 for order: " . $order->getIncrementId());
                return;
            }

            // Create the queue record now that we have the order ID
            $this->multiPaymentQueueService->addToQueue(
                (int)$orderId,
                $queueData['increment_id'],
                $queueData['payment_method'],
                '', // Primary transaction ID will be set later when primary response comes
                $queueData['secondary_method_type'],
                $queueData['secondary_amount'],
                $queueData['request_data'],
                $queueData['status']
            );

            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - {$type} queue record created successfully for order: " . $order->getIncrementId());

            // Remove the temporary data from payment additional information
            $payment->unsAdditionalInformation($additionalKey);
            $payment->save();

            $this->logger->info(
                "CreateMultiPaymentQueueAfterOrderSave - {$type} queue record created after order save for order {$order->getIncrementId()}",
                [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'amount' => $queueData['secondary_amount'],
                    'type' => $type
                ]
            );

        } catch (\Exception $e) {
            $this->logger->error(
                "CreateMultiPaymentQueueAfterOrderSave - Failed to create {$type} queue record for order {$order->getIncrementId()}: {$e->getMessage()}",
                [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'error' => $e->getMessage(),
                    'type' => $type
                ]
            );
        }
    }
}
