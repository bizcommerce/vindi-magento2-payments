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

namespace Vindi\VP\Cron;

use Vindi\VP\Model\MultiPaymentQueueService;
use Vindi\VP\Model\MultiPaymentQueue;
use Vindi\VP\Gateway\Http\Client\Api;
use Vindi\VP\Gateway\Http\Client\Api\Create;
use Vindi\VP\Helper\Data;
use Vindi\VP\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Class ProcessMultiPaymentQueue
 * Processes pending multi-payment method secondary requests
 */
class ProcessMultiPaymentQueue
{
    /**
     * @var MultiPaymentQueueService
     */
    private $queueService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Create
     */
    private $apiCreate;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var Config
     */
    private $helperConfig;

    /**
     * @param MultiPaymentQueueService $queueService
     * @param LoggerInterface $logger
     * @param Create $apiCreate
     * @param Data $helper
     * @param Config $helperConfig
     */
    public function __construct(
        MultiPaymentQueueService $queueService,
        LoggerInterface $logger,
        Create $apiCreate,
        Data $helper,
        Config $helperConfig
    ) {
        $this->queueService = $queueService;
        $this->logger = $logger;
        $this->apiCreate = $apiCreate;
        $this->helper = $helper;
        $this->helperConfig = $helperConfig;
    }

    /**
     * Process pending multi-payment queue items
     *
     * @return void
     */
    public function execute()
    {
        $this->logger->info('Starting multi-payment queue processing');

        try {
            $pendingItems = $this->queueService->getPendingItems(50);

            if (empty($pendingItems)) {
                $this->logger->info('No pending multi-payment queue items found');
                return;
            }

            $this->logger->info('Found ' . count($pendingItems) . ' pending queue items');

            foreach ($pendingItems as $queueItem) {
                $this->processQueueItem($queueItem);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error processing multi-payment queue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        $this->logger->info('Multi-payment queue processing completed');
    }

    /**
     * Process individual queue item
     *
     * @param MultiPaymentQueue $queueItem
     * @return void
     */
    private function processQueueItem(MultiPaymentQueue $queueItem): void
    {
        try {
            $this->logger->info('Processing queue item', [
                'id' => $queueItem->getId(),
                'order_id' => $queueItem->getOrderId(),
                'increment_id' => $queueItem->getIncrementId(),
                'secondary_method' => $queueItem->getSecondaryMethodType(),
                'attempt' => $queueItem->getAttempts() + 1
            ]);

            // Mark as processing
            $this->queueService->markAsProcessing($queueItem);

            // Process based on secondary method type
            $response = $this->processSecondaryPayment($queueItem);

            if ($this->isSuccessfulResponse($response)) {
                $this->queueService->updateStatus(
                    $queueItem,
                    MultiPaymentQueue::STATUS_EXECUTED,
                    $response
                );

                $this->logger->info('Queue item processed successfully', [
                    'id' => $queueItem->getId(),
                    'transaction_id' => $response['tid'] ?? 'N/A',
                    'status' => 'executed'
                ]);

                // Handle successful payment specific logic
                $this->handleSuccessfulPayment($queueItem, $response);

            } else {
                $errorMessage = $this->extractErrorMessage($response);
                
                if ($queueItem->canRetry()) {
                    $this->queueService->updateStatus(
                        $queueItem,
                        MultiPaymentQueue::STATUS_PENDING,
                        $response,
                        $errorMessage
                    );
                    
                    $this->logger->warning('Queue item failed, will retry', [
                        'id' => $queueItem->getId(),
                        'error' => $errorMessage,
                        'attempt' => $queueItem->getAttempts(),
                        'max_attempts' => $queueItem->getMaxAttempts()
                    ]);
                } else {
                    $this->queueService->updateStatus(
                        $queueItem,
                        MultiPaymentQueue::STATUS_FAILED,
                        $response,
                        $errorMessage
                    );
                    
                    $this->logger->error('Queue item failed permanently', [
                        'id' => $queueItem->getId(),
                        'error' => $errorMessage
                    ]);
                }
            }

        } catch (\Exception $e) {
            $errorMessage = 'Exception during processing: ' . $e->getMessage();
            
            if ($queueItem->canRetry()) {
                $this->queueService->updateStatus(
                    $queueItem,
                    MultiPaymentQueue::STATUS_PENDING,
                    [],
                    $errorMessage
                );
            } else {
                $this->queueService->updateStatus(
                    $queueItem,
                    MultiPaymentQueue::STATUS_FAILED,
                    [],
                    $errorMessage
                );
            }

            $this->logger->error('Exception processing queue item', [
                'id' => $queueItem->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Process secondary payment request
     *
     * @param MultiPaymentQueue $queueItem
     * @return array
     */
    private function processSecondaryPayment(MultiPaymentQueue $queueItem): array
    {
        $requestData = $queueItem->getRequestData();
        
        try {
            // Use the existing API Create client
            $apiResponse = $this->apiCreate->execute($requestData);
            
            $this->logger->debug('Secondary payment API response', [
                'queue_item_id' => $queueItem->getId(),
                'response' => $apiResponse
            ]);

            return $apiResponse['response'] ?? $apiResponse;

        } catch (\Exception $e) {
            $this->logger->error('Error making secondary payment API request', [
                'queue_item_id' => $queueItem->getId(),
                'error' => $e->getMessage()
            ]);

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Handle successful payment processing
     *
     * @param MultiPaymentQueue $queueItem
     * @param array $response
     * @return void
     */
    private function handleSuccessfulPayment(MultiPaymentQueue $queueItem, array $response): void
    {
        // For PIX payments, create payment link if needed
        if ($queueItem->getSecondaryMethodType() === MultiPaymentQueue::SECONDARY_METHOD_PIX) {
            $this->handleSuccessfulPixPayment($queueItem, $response);
        }
        
        // For Bolepix payments, handle both bankslip and PIX data
        if ($queueItem->getSecondaryMethodType() === MultiPaymentQueue::SECONDARY_METHOD_BOLEPIX) {
            $this->handleSuccessfulBolepixPayment($queueItem, $response);
        }
    }

    /**
     * Handle successful Bolepix payment
     *
     * @param MultiPaymentQueue $queueItem
     * @param array $response
     * @return void
     */
    private function handleSuccessfulBolepixPayment(MultiPaymentQueue $queueItem, array $response): void
    {
        // Extract Bolepix data (bankslip + PIX combined)
        $bankSlipUrl = $response['bankslip_url'] ?? '';
        $bankSlipCode = $response['bankslip_code'] ?? '';
        $pixCode = $response['pix_code'] ?? '';
        $pixUrl = $response['pix_url'] ?? '';
        $pixExpiration = $response['pix_expiration_date'] ?? '';

        $this->logger->info('Bolepix payment created successfully', [
            'queue_item_id' => $queueItem->getId(),
            'order_id' => $queueItem->getOrderId(),
            'increment_id' => $queueItem->getIncrementId(),
            'has_bankslip' => !empty($bankSlipUrl),
            'has_pix' => !empty($pixCode)
        ]);

        // Here you could create payment links or update order information as needed
        // The response should contain both bankslip and PIX payment options
    }

    /**
     * Handle successful PIX payment
     *
     * @param MultiPaymentQueue $queueItem
     * @param array $response
     * @return void
     */
    private function handleSuccessfulPixPayment(MultiPaymentQueue $queueItem, array $response): void
    {
        // Extract PIX data and create payment link if needed
        $pixCode = $response['pix_code'] ?? '';
        $pixUrl = $response['pix_url'] ?? '';
        $pixExpiration = $response['pix_expiration_date'] ?? '';

        if ($pixCode && $pixUrl) {
            // Here you could create a payment link or update order information
            $this->logger->info('PIX payment created successfully', [
                'queue_item_id' => $queueItem->getId(),
                'pix_code' => substr($pixCode, 0, 20) . '...', // Log partial code for security
                'pix_url' => $pixUrl
            ]);
        }
    }

    /**
     * Check if response indicates success
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulResponse(array $response): bool
    {
        if (isset($response['error']) && $response['error']) {
            return false;
        }

        $statusId = $response['transaction']['status_id'] ?? $response['status_id'] ?? null;

        return in_array($statusId, [1, 6, 11]); // 1 - captured, 6 - authorized, 11 - pending_capture
    }

    /**
     * Extract error message from response
     *
     * @param array $response
     * @return string
     */
    private function extractErrorMessage(array $response): string
    {
        if (isset($response['message'])) {
            return $response['message'];
        }

        if (isset($response['error_message'])) {
            return $response['error_message'];
        }

        if (isset($response['status_reason'])) {
            return $response['status_reason'];
        }

        return 'Unknown error occurred';
    }
}
