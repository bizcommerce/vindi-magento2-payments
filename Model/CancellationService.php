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
            $transactionId = $transaction['order_number'] ?? '';
            $statusId = $transaction['status_id'] ?? '';

            if (empty($transactionId)) {
                throw new CancellationException('Missing transaction ID in webhook data');
            }

            // Check if it's a multi-payment transaction
            if (preg_match('/(.*?)-(\d{2})$/', $transactionId, $matches)) {
                return $this->processMultiPaymentCancellation($matches[1], $transaction);
            } else {
                return $this->processSinglePaymentCancellation($transactionId, $transaction);
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
        $this->logger->execute('Processing multi-payment cancellation for order: ' . $orderIncrementId, 'vindi-cancellation');

        // Load order
        $order = $this->helperOrder->loadOrder($orderIncrementId);
        if (!$order || !$order->getId()) {
            throw new CancellationException("Order {$orderIncrementId} not found");
        }

        $results = [];
        $allSuccessful = true;

        // Find all related transactions for this order
        $relatedTransactions = $this->findRelatedTransactions($order);

        foreach ($relatedTransactions as $txnId) {
            $result = $this->cancelTransaction($txnId, null, (int)$order->getStoreId());
            $results[$txnId] = $result;

            if ($result->getStatus() !== self::CANCELLATION_SUCCESS) {
                $allSuccessful = false;
            }
        }

        // Cancel order in Magento if all transactions were cancelled
        if ($allSuccessful) {
            $this->cancelMagentoOrder($order, 'All related transactions cancelled via webhook');
            $status = self::CANCELLATION_SUCCESS;
            $message = 'All related transactions cancelled successfully';
        } else {
            $status = self::CANCELLATION_PARTIAL;
            $message = 'Some transactions could not be cancelled';
        }

        return new CancellationResult($status, $results, $message);
    }

    /**
     * Process single payment cancellation
     *
     * @param string $transactionId
     * @param array $transactionData
     * @return CancellationResult
     */
    private function processSinglePaymentCancellation(string $transactionId, array $transactionData): CancellationResult
    {
        $this->logger->execute('Processing single payment cancellation for transaction: ' . $transactionId, 'vindi-cancellation');

        // Load order
        $order = $this->helperOrder->loadOrder($transactionId);
        if (!$order || !$order->getId()) {
            throw new CancellationException("Order {$transactionId} not found");
        }

        // Cancel transaction via API
        $result = $this->cancelTransaction(
            $transactionData['token_transaction'] ?? $transactionId,
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
            $this->helperOrder->cancelOrder($order, (float)$order->getGrandTotal(), true);
            $order->addCommentToStatusHistory("Order cancelled: {$reason}");
            $order->save();

            $this->logger->execute('Order cancelled in Magento - Order ID: ' . $order->getIncrementId() . ', Reason: ' . $reason, 'vindi-cancellation');

        } catch (\Exception $e) {
            $this->logger->execute('Failed to cancel order in Magento - Order ID: ' . $order->getIncrementId() . ', Error: ' . $e->getMessage(), 'vindi-cancellation');
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
