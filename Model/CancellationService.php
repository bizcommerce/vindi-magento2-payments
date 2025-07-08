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
            // Extract transaction info from webhook
            $transaction = $webhookData['transaction'] ?? [];
            $orderNumber = $transaction['order_number'] ?? '';  // This is Magento increment ID
            $vindiTransactionId = $transaction['transaction_id'] ?? '';  // This is Vindi transaction ID
            $statusId = $transaction['status_id'] ?? '';

            file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Webhook data - Order Number: ' . $orderNumber . ', Vindi Transaction ID: ' . $vindiTransactionId . ', Status: ' . $statusId . PHP_EOL, FILE_APPEND);

            if (empty($orderNumber)) {
                throw new CancellationException('Missing order_number in webhook data');
            }

            // Check if it's a multi-payment transaction
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
            // Get access token
            $accessToken = $this->helperData->getAccessToken($storeId);

            // Call Vindi API to cancel transaction
            $response = $this->api->cancel()->cancelWithAmount(
                $transactionId,
                $accessToken,
                $refundAmount,
                $storeId
            );

            // Log API response
            $this->logger->execute('Vindi API cancellation response for ' . $transactionId . ': ' . json_encode($response), 'vindi-cancellation');

            // Check if cancellation was successful
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
        file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Processing multi-payment cancellation for order: ' . $orderIncrementId . PHP_EOL, FILE_APPEND);
        $this->logger->execute('Processing multi-payment cancellation for order: ' . $orderIncrementId, 'vindi-cancellation');

        // Load order
        $order = $this->helperOrder->loadOrder($orderIncrementId);
        if (!$order || !$order->getId()) {
            file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Order not found: ' . $orderIncrementId . PHP_EOL, FILE_APPEND);
            throw new CancellationException("Order {$orderIncrementId} not found");
        }

        // Extract transaction_id from webhook (this is the Vindi transaction ID to cancel)
        $currentTransactionId = $transactionData['transaction_id'] ?? null;
        file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Current transaction ID to cancel: ' . $currentTransactionId . PHP_EOL, FILE_APPEND);

        // Check for existing invoices to cancel (not refund for STATUS_DENIED)
        $invoices = $order->getInvoiceCollection();
        if (count($invoices) > 0) {
            file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Found ' . count($invoices) . ' invoices. Cancelling order and invoices...' . PHP_EOL, FILE_APPEND);
        } else {
            file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] No invoices found. Will cancel order directly.' . PHP_EOL, FILE_APPEND);
        }

        $results = [];
        $allSuccessful = true;

        // Cancel the specific transaction from webhook first
        if ($currentTransactionId) {
            file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Cancelling current transaction: ' . $currentTransactionId . PHP_EOL, FILE_APPEND);
            $result = $this->cancelTransaction($currentTransactionId, null, (int)$order->getStoreId());
            $results[$currentTransactionId] = $result;

            if ($result->getStatus() !== self::CANCELLATION_SUCCESS) {
                $allSuccessful = false;
                file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Current transaction cancellation failed: ' . $currentTransactionId . PHP_EOL, FILE_APPEND);
            } else {
                file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Current transaction cancelled successfully: ' . $currentTransactionId . PHP_EOL, FILE_APPEND);
                $this->logger->execute('Transaction cancelled successfully: ' . $currentTransactionId, 'vindi-cancellation');
            }
        }

        // Find and cancel all other related transactions for this order
        $relatedTransactions = $this->findRelatedTransactions($order);
        file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Found related transactions: ' . json_encode($relatedTransactions) . PHP_EOL, FILE_APPEND);

        foreach ($relatedTransactions as $txnId) {
            // Skip if already processed
            if ($txnId === $currentTransactionId) {
                continue;
            }
            
            file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Cancelling related transaction: ' . $txnId . PHP_EOL, FILE_APPEND);
            $result = $this->cancelTransaction($txnId, null, (int)$order->getStoreId());
            $results[$txnId] = $result;

            if ($result->getStatus() !== self::CANCELLATION_SUCCESS) {
                $allSuccessful = false;
                file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Related transaction cancellation failed: ' . $txnId . PHP_EOL, FILE_APPEND);
            } else {
                file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Related transaction cancelled successfully: ' . $txnId . PHP_EOL, FILE_APPEND);
                $this->logger->execute('Transaction cancelled successfully: ' . $txnId, 'vindi-cancellation');
            }
        }

        // Always cancel order in Magento for STATUS_DENIED (force cancellation)
        file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Forcing Magento order cancellation.' . PHP_EOL, FILE_APPEND);
        $this->cancelMagentoOrder($order, 'Order cancellation due to payment denial');

        if ($allSuccessful) {
            $status = self::CANCELLATION_SUCCESS;
            $message = 'All related transactions cancelled successfully and order cancelled';
        } else {
            file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Some transactions failed to cancel. Status: PARTIAL' . PHP_EOL, FILE_APPEND);
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

        // Load order using order_number (increment ID)
        $order = $this->helperOrder->loadOrder($orderNumber);
        if (!$order || !$order->getId()) {
            throw new CancellationException("Order {$orderNumber} not found");
        }

        // Use transaction_id from webhook data to cancel at Vindi
        $vindiTransactionId = $transactionData['transaction_id'] ?? null;
        file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Single payment - Order: ' . $orderNumber . ', Vindi Transaction ID: ' . $vindiTransactionId . PHP_EOL, FILE_APPEND);

        if (!$vindiTransactionId) {
            throw new CancellationException("Missing transaction_id in webhook data for order {$orderNumber}");
        }

        // Cancel transaction via API using the correct Vindi transaction ID
        $result = $this->cancelTransaction(
            $vindiTransactionId,
            null,
            (int)$order->getStoreId()
        );

        // Cancel order in Magento if cancellation was successful
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

        // Get primary transaction ID
        $primaryTid = $payment->getAdditionalInformation('tid');
        if ($primaryTid) {
            $transactions[] = $primaryTid;
        }

        // Get multi-payment info for secondary transactions
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
            file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Attempting to cancel order ' . $order->getIncrementId() . ' - Can cancel: ' . ($order->canCancel() ? 'YES' : 'NO') . PHP_EOL, FILE_APPEND);

            // Force cancellation using HelperOrder regardless of canCancel()
            $this->helperOrder->cancelOrder($order, (float)$order->getGrandTotal(), true);
            $order->addCommentToStatusHistory("Order cancelled: {$reason}");
            $order->save();

            file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Order cancelled successfully in Magento.' . PHP_EOL, FILE_APPEND);
            $this->logger->execute('Order cancelled in Magento - Order ID: ' . $order->getIncrementId() . ', Reason: ' . $reason, 'vindi-cancellation');

        } catch (\Exception $e) {
            file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Failed to cancel order: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            $this->logger->execute('Failed to cancel order in Magento - Order ID: ' . $order->getIncrementId() . ', Error: ' . $e->getMessage(), 'vindi-cancellation');

            // Try alternative approach - direct status change if normal cancellation fails
            try {
                $order->setState('canceled');
                $order->setStatus('canceled');
                $order->addCommentToStatusHistory("Order force cancelled: {$reason} (direct status change)");
                $order->save();
                file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Order force cancelled via direct status change.' . PHP_EOL, FILE_APPEND);
            } catch (\Exception $e2) {
                file_put_contents('/tmp/vindi_cancellation_debug.log', date('Y-m-d H:i:s') . ' - [CancellationService] Failed to force cancel order: ' . $e2->getMessage() . PHP_EOL, FILE_APPEND);
            }
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
        // Check for success indicators in Vindi API response
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
