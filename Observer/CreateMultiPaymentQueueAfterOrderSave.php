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
     * Create multi-payment queue records with data provided by the event
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        $queueData = $observer->getEvent()->getQueueData();

        $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Observer triggered for order: " . ($order ? $order->getIncrementId() : 'NULL'));

        if (!$order) {
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - No order found");
            return;
        }

        if (!$queueData || !is_array($queueData)) {
            $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - No queue data provided for order: " . $order->getIncrementId());
            return;
        }

        $this->logger->info("CreateMultiPaymentQueueAfterOrderSave - Processing queue data for order: " . $order->getIncrementId() . " - Data: " . json_encode($queueData));

        try {
            // Use null for order_id if not available yet, will be updated later in response handler
        $orderId = $order->getId() ?: 0;

            // Create the queue record with the provided data
            $this->multiPaymentQueueService->addToQueue(
                $orderId,
                $queueData['increment_id'],
                $queueData['payment_method'],
                '', // Primary transaction ID will be set later when primary response comes
                $queueData['secondary_method_type'],
                $queueData['secondary_amount'],
                $queueData['request_data'],
                $queueData['status']
            );

            $this->logger->info(
                "CreateMultiPaymentQueueAfterOrderSave - Queue record created successfully for order {$order->getIncrementId()}",
                [
                    'order_id' => $orderId,
                    'increment_id' => $order->getIncrementId(),
                    'secondary_method_type' => $queueData['secondary_method_type'],
                    'secondary_amount' => $queueData['secondary_amount']
                ]
            );

        } catch (\Exception $e) {
            $this->logger->error(
                "CreateMultiPaymentQueueAfterOrderSave - Failed to create queue record for order {$order->getIncrementId()}: {$e->getMessage()}",
                [
                    'order_id' => $order->getId() ?: null,
                    'increment_id' => $order->getIncrementId(),
                    'error' => $e->getMessage()
                ]
            );
        }
    }
}
