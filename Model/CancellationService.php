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

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Vindi\VP\Gateway\Http\Client\Api;
use Vindi\VP\Helper\Data;
use Vindi\VP\Helper\Order as HelperOrder;
use Vindi\VP\Helper\Logger;
use Vindi\VP\Model\Exception\CancellationException;
use Vindi\VP\Model\CancellationResult;
use Vindi\VP\Model\MultiPaymentQueueService;

class CancellationService
{
    public const CANCELLATION_SUCCESS = 'success';
    public const CANCELLATION_FAILED = 'failed';
    public const CANCELLATION_PARTIAL = 'partial';

    /**
     * @var Api
     */
    private $api;

    /**
     * @var Data
     */
    private $helperData;

    /**
     * @var HelperOrder
     */
    private $helperOrder;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var MultiPaymentQueueService
     */
    private $multiPaymentQueueService;

    /**
     * Constructor
     *
     * @param Api $api
     * @param Data $helperData
     * @param HelperOrder $helperOrder
     * @param Logger $logger
     * @param MultiPaymentQueueService $multiPaymentQueueService
     */
    public function __construct(
        Api $api,
        Data $helperData,
        HelperOrder $helperOrder,
        Logger $logger,
        MultiPaymentQueueService $multiPaymentQueueService
    ) {
        $this->api = $api;
        $this->helperData = $helperData;
        $this->helperOrder = $helperOrder;
        $this->logger = $logger;
        $this->multiPaymentQueueService = $multiPaymentQueueService;
    }

    /**
     * Process cancellation from webhook data
     *
     * @param array $webhookData
     * @return CancellationResult
     */
    public function processWebhookCancellation(array $webhookData): CancellationResult
    {
        $this->logger->execute('Starting webhook cancellation process: ' . json_encode($webhookData), 'vindi-cancellation');

        try {
            $transaction = $webhookData['transaction'] ?? [];
            $orderNumber = $transaction['order_number'] ?? '';
            $vindiTransactionId = $transaction['transaction_id'] ?? '';
            $statusId = $transaction['status_id'] ?? '';

            if (empty($orderNumber)) {
                throw new CancellationException('Missing order_number in webhook data');
            }

            if (preg_match('/(.*?)-(\d{2})$/', $orderNumber, $matches)) {
                return $this->processMultiPaymentCancellation($matches[1], $transaction);
            } else {
                return $this->processSinglePaymentCancellation($orderNumber, $transaction);
            }

        } catch (\Exception $e) {
            $this->logger->execute('Webhook cancellation failed: ' . $e->getMessage(), 'vindi-cancellation');

            return new CancellationResult(
                self::CANCELLATION_FAILED,
                [],
                $e->getMessage()
            );
        }
    }

    /**
     * Cancel specific transaction by ID
     *
     * @param string $transactionId
     * @param float|null $refundAmount
     * @param int|null $storeId
     * @return CancellationResult
     */
    public function cancelTransaction(
        string $transactionId,
        ?float $refundAmount = null,
        ?int $storeId = null
    ): CancellationResult {
        $this->logger->execute('Starting transaction cancellation - ID: ' . $transactionId . ', Amount: ' . ($refundAmount ?: 'full'), 'vindi-cancellation');

        try {
            $accessToken = $this->helperData->getAccessToken($storeId);

            $response = $this->api->cancel()->cancelWithAmount(
                $transactionId,
                $accessToken,
                $refundAmount,
                $storeId
            );

            $this->logger->execute('Vindi API cancellation response for ' . $transactionId . ': ' . json_encode($response), 'vindi-cancellation');

            if ($this->isCancellationSuccessful($response)) {
                return new CancellationResult(
                    self::CANCELLATION_SUCCESS,
                    [$transactionId => $response],
                    'Transaction cancelled successfully'
                );
            } else {
                return new CancellationResult(
                    self::CANCELLATION_FAILED,
                    [$transactionId => $response],
                    'Transaction cancellation failed at Vindi API'
                );
            }

        } catch (\Exception $e) {
            $this->logger->execute('Transaction cancellation failed for ' . $transactionId . ': ' . $e->getMessage(), 'vindi-cancellation');

            return new CancellationResult(
                self::CANCELLATION_FAILED,
                [],
                $e->getMessage()
            );
        }
    }

    /**
     * Process multi-payment cancellation
     *
     * @param string $orderIncrementId
     * @param array $transactionData
     * @return CancellationResult
     */
    private function processMultiPaymentCancellation(string $orderIncrementId, array $transactionData): CancellationResult
    {
        $this->logger->execute('Processing multi-payment cancellation for order: ' . $orderIncrementId, 'vindi-cancellation');

        $order = $this->helperOrder->loadOrder($orderIncrementId);
        if (!$order || !$order->getId()) {
            throw new CancellationException("Order {$orderIncrementId} not found");
        }

        $currentTransactionId = $transactionData['transaction_id'] ?? null;

        $invoices = $order->getInvoiceCollection();

        $results = [];
        $allSuccessful = true;

        if ($currentTransactionId) {
            $result = $this->cancelTransaction($currentTransactionId, null, (int)$order->getStoreId());
            $results[$currentTransactionId] = $result;

            if ($result->getStatus() !== self::CANCELLATION_SUCCESS) {
                $allSuccessful = false;
            } else {
                $this->logger->execute('Transaction cancelled successfully: ' . $currentTransactionId, 'vindi-cancellation');
            }
        }

        $relatedTransactions = $this->findRelatedTransactions($order);

        foreach ($relatedTransactions as $txnId) {
            if ($txnId === $currentTransactionId) {
                continue;
            }
            
            $result = $this->cancelTransaction($txnId, null, (int)$order->getStoreId());
            $results[$txnId] = $result;

            if ($result->getStatus() !== self::CANCELLATION_SUCCESS) {
                $allSuccessful = false;
            } else {
                $this->logger->execute('Transaction cancelled successfully: ' . $txnId, 'vindi-cancellation');
            }
        }

        $this->cancelMagentoOrder($order, 'Order cancellation due to payment denial');

        if ($allSuccessful) {
            $status = self::CANCELLATION_SUCCESS;
            $message = 'All related transactions cancelled successfully and order cancelled';
        } else {
            $status = self::CANCELLATION_PARTIAL;
            $message = 'Order cancelled in Magento, but some Vindi transactions could not be cancelled';
        }

        return new CancellationResult($status, $results, $message);
    }

    /**
     * Process single payment cancellation
     *
     * @param string $orderNumber - This is the order_number (Magento increment ID)
     * @param array $transactionData
     * @return CancellationResult
     */
    private function processSinglePaymentCancellation(string $orderNumber, array $transactionData): CancellationResult
    {
        $this->logger->execute('Processing single payment cancellation for order: ' . $orderNumber, 'vindi-cancellation');

        $order = $this->helperOrder->loadOrder($orderNumber);
        if (!$order || !$order->getId()) {
            throw new CancellationException("Order {$orderNumber} not found");
        }

        $vindiTransactionId = $transactionData['transaction_id'] ?? null;

        if (!$vindiTransactionId) {
            throw new CancellationException("Missing transaction_id in webhook data for order {$orderNumber}");
        }

        $result = $this->cancelTransaction(
            $vindiTransactionId,
            null,
            (int)$order->getStoreId()
        );

        if ($result->getStatus() === self::CANCELLATION_SUCCESS) {
            $this->cancelMagentoOrder($order, 'Transaction cancelled via webhook');
        }

        return $result;
    }

    /**
     * Find all related transactions for a multi-payment order
     *
     * @param Order $order
     * @return array
     */
    private function findRelatedTransactions(Order $order): array
    {
        $transactions = [];
        $payment = $order->getPayment();

        $primaryTid = $payment->getAdditionalInformation('tid');
        if ($primaryTid) {
            $transactions[] = $primaryTid;
        }

        $multiPaymentInfo = $payment->getAdditionalInformation('multi_payment_info') ?: [];
        foreach ($multiPaymentInfo as $info) {
            if (isset($info['transaction_id']) && !in_array($info['transaction_id'], $transactions)) {
                $transactions[] = $info['transaction_id'];
            }
        }

        return $transactions;
    }

    /**
     * Cancel order in Magento
     *
     * @param Order $order
     * @param string $reason
     * @return void
     */
    private function cancelMagentoOrder(Order $order, string $reason): void
    {
        try {
            // First cancel/refund all paid invoices
            $this->cancelOrderInvoices($order, $reason);
            
            // Then cancel the order
            $this->helperOrder->cancelOrder($order, (float)$order->getGrandTotal(), true);
            
            // Force set proper status and state if needed
            $this->ensureOrderCancellation($order, $reason);

            $this->logger->execute('Order cancelled in Magento - Order ID: ' . $order->getIncrementId() . ', Reason: ' . $reason, 'vindi-cancellation');

        } catch (\Exception $e) {
            $this->logger->execute('Failed to cancel order in Magento - Order ID: ' . $order->getIncrementId() . ', Error: ' . $e->getMessage(), 'vindi-cancellation');

            // Force cancellation as fallback
            $this->forceOrderCancellation($order, $reason);
        }
    }

    /**
     * Cancel all paid invoices for the order
     *
     * @param Order $order
     * @param string $reason
     * @return void
     */
    private function cancelOrderInvoices(Order $order, string $reason): void
    {
        try {
            $invoices = $order->getInvoiceCollection();
            
            foreach ($invoices as $invoice) {
                /** @var \Magento\Sales\Model\Order\Invoice $invoice */
                if ($invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_PAID) {
                    $this->logger->execute('Cancelling paid invoice ' . $invoice->getIncrementId() . ' for order ' . $order->getIncrementId(), 'vindi-cancellation');
                    
                    try {
                        // Try to create credit memo first
                        if ($invoice->canRefund()) {
                            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                            $creditmemoFactory = $objectManager->get(\Magento\Sales\Model\Order\CreditmemoFactory::class);
                            $creditmemoService = $objectManager->get(\Magento\Sales\Model\Service\CreditmemoService::class);

                            $creditmemo = $creditmemoFactory->createByInvoice($invoice);
                            $creditmemoService->refund($creditmemo);
                            
                            $this->logger->execute('Credit memo created for invoice ' . $invoice->getIncrementId(), 'vindi-cancellation');
                        } else {
                            // Force cancel the invoice if can't refund
                            $invoice->cancel();
                            
                            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                            $invoiceRepository = $objectManager->get(\Magento\Sales\Api\InvoiceRepositoryInterface::class);
                            $invoiceRepository->save($invoice);
                            
                            $this->logger->execute('Invoice ' . $invoice->getIncrementId() . ' force cancelled', 'vindi-cancellation');
                        }
                    } catch (\Exception $invoiceException) {
                        $this->logger->execute('Failed to cancel invoice ' . $invoice->getIncrementId() . ': ' . $invoiceException->getMessage(), 'vindi-cancellation');
                        
                        // Force update invoice state as last resort
                        try {
                            $invoice->setState(\Magento\Sales\Model\Order\Invoice::STATE_CANCELED);
                            $invoice->addComment("Invoice cancelled due to payment cancellation: {$reason}");
                            
                            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                            $invoiceRepository = $objectManager->get(\Magento\Sales\Api\InvoiceRepositoryInterface::class);
                            $invoiceRepository->save($invoice);
                            
                            $this->logger->execute('Invoice ' . $invoice->getIncrementId() . ' state forced to cancelled', 'vindi-cancellation');
                        } catch (\Exception $forceException) {
                            $this->logger->execute('Failed to force cancel invoice ' . $invoice->getIncrementId() . ': ' . $forceException->getMessage(), 'vindi-cancellation');
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->execute('Error cancelling invoices for order ' . $order->getIncrementId() . ': ' . $e->getMessage(), 'vindi-cancellation');
        }
    }

    /**
     * Ensure order is properly cancelled
     *
     * @param Order $order
     * @param string $reason
     * @return void
     */
    private function ensureOrderCancellation(Order $order, string $reason): void
    {
        try {
            // Check if order is actually cancelled
            if ($order->getState() !== \Magento\Sales\Model\Order::STATE_CANCELED) {
                $this->logger->execute('Order ' . $order->getIncrementId() . ' not properly cancelled, forcing cancellation', 'vindi-cancellation');
                
                $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
                $order->setStatus('canceled');
            }

            // Get cancelled status from configuration
            $cancelledStatus = $this->helperData->getConfig(
                'cancelled_order_status',
                $order->getPayment()->getMethod(),
                'payment',
                $order->getStoreId()
            );
            
            if ($cancelledStatus && $order->getStatus() !== $cancelledStatus) {
                $order->setStatus($cancelledStatus);
                $this->logger->execute('Order ' . $order->getIncrementId() . ' status set to configured cancelled status: ' . $cancelledStatus, 'vindi-cancellation');
            }

            $order->addCommentToStatusHistory("Order cancelled: {$reason}");
            $order->save();
            
        } catch (\Exception $e) {
            $this->logger->execute('Failed to ensure order cancellation for ' . $order->getIncrementId() . ': ' . $e->getMessage(), 'vindi-cancellation');
            throw $e;
        }
    }

    /**
     * Force order cancellation as fallback
     *
     * @param Order $order
     * @param string $reason
     * @return void
     */
    private function forceOrderCancellation(Order $order, string $reason): void
    {
        try {
            $this->logger->execute('Force cancelling order ' . $order->getIncrementId(), 'vindi-cancellation');
            
            // Force cancel all invoices first
            $invoices = $order->getInvoiceCollection();
            foreach ($invoices as $invoice) {
                try {
                    if ($invoice->getState() !== \Magento\Sales\Model\Order\Invoice::STATE_CANCELED) {
                        $invoice->setState(\Magento\Sales\Model\Order\Invoice::STATE_CANCELED);
                        $invoice->addComment("Invoice force cancelled: {$reason}");
                        
                        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                        $invoiceRepository = $objectManager->get(\Magento\Sales\Api\InvoiceRepositoryInterface::class);
                        $invoiceRepository->save($invoice);
                    }
                } catch (\Exception $invoiceException) {
                    $this->logger->execute('Failed to force cancel invoice ' . $invoice->getIncrementId() . ': ' . $invoiceException->getMessage(), 'vindi-cancellation');
                }
            }
            
            // Force set order state and status
            $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
            $order->setStatus('canceled');
            $order->addCommentToStatusHistory("Order force cancelled: {$reason} (direct status change)");
            $order->save();
            
            $this->logger->execute('Order ' . $order->getIncrementId() . ' force cancelled successfully', 'vindi-cancellation');
            
        } catch (\Exception $e2) {
            $this->logger->execute('Failed to force cancel order ' . $order->getIncrementId() . ': ' . $e2->getMessage(), 'vindi-cancellation');
        }
    }

    /**
     * Check if API response indicates successful cancellation
     *
     * @param array $response
     * @return bool
     */
    private function isCancellationSuccessful(array $response): bool
    {
        if (isset($response['status']) && $response['status'] === 200) {
            return true;
        }

        if (isset($response['response']['status_id']) && $response['response']['status_id'] == '5') {
            return true;
        }

        if (isset($response['response']['message_response']['message']) &&
            $response['response']['message_response']['message'] === 'success') {
            return true;
        }

        return false;
    }
}
