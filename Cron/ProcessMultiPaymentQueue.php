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
use Vindi\VP\Helper\Logger as VindiLogger;
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
     * @var Api
     */
    private $api;

    /**
     * @var VindiLogger
     */
    private $vindiLogger;

    /**
     * @param MultiPaymentQueueService $queueService
     * @param LoggerInterface $logger
     * @param Create $apiCreate
     * @param Data $helper
     * @param Config $helperConfig
     * @param VindiLogger $vindiLogger
     * @param Api $api
     */
    public function __construct(
        MultiPaymentQueueService $queueService,
        LoggerInterface $logger,
        Create $apiCreate,
        Data $helper,
        Config $helperConfig,
        VindiLogger $vindiLogger,
        Api $api
    ) {
        $this->queueService = $queueService;
        $this->logger = $logger;
        $this->apiCreate = $apiCreate;
        $this->helper = $helper;
        $this->helperConfig = $helperConfig;
        $this->vindiLogger = $vindiLogger;
        $this->api = $api;
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

            // Log para debug no vindi.log
            $this->vindiLogger->execute('Starting multi-payment queue item processing', 'multi-payment-queue');
            $this->vindiLogger->execute([
                'queue_item_id' => $queueItem->getId(),
                'order_id' => $queueItem->getOrderId(),
                'increment_id' => $queueItem->getIncrementId(),
                'secondary_method' => $queueItem->getSecondaryMethodType(),
                'secondary_amount' => $queueItem->getSecondaryAmount(),
                'current_attempt' => $queueItem->getAttempts() + 1,
                'max_attempts' => $queueItem->getMaxAttempts()
            ], 'multi-payment-queue');

            // Mark as processing
            $this->queueService->markAsProcessing($queueItem);

            // Process based on secondary method type
            $response = $this->processSecondaryPayment($queueItem);

            // Debug: Response received
            $this->debugQueueProcessing($queueItem, $response, 'response_received');

            // Log response detalhado
            $this->vindiLogger->execute('Multi-payment API response received', 'multi-payment-queue');
            $this->vindiLogger->execute($response, 'multi-payment-queue');

            if ($this->isSuccessfulResponse($response, $queueItem->getSecondaryMethodType())) {
                // Debug: Success detected
                $this->debugQueueProcessing($queueItem, $response, 'success_detected');
                $this->queueService->updateStatus(
                    $queueItem,
                    MultiPaymentQueue::STATUS_EXECUTED,
                    $response
                );

                $this->logger->info('Queue item processed successfully', [
                    'id' => $queueItem->getId(),
                    'transaction_id' => $response['tid'] ?? $response['transaction_id'] ?? 'N/A',
                    'status' => 'executed'
                ]);

                // Log sucesso no vindi.log
                $this->vindiLogger->execute('Multi-payment queue item processed successfully', 'multi-payment-queue');
                $this->vindiLogger->execute([
                    'queue_item_id' => $queueItem->getId(),
                    'status' => 'executed',
                    'response_summary' => $this->getResponseSummary($response, $queueItem->getSecondaryMethodType())
                ], 'multi-payment-queue');

                // Handle successful payment specific logic
                $this->handleSuccessfulPayment($queueItem, $response);

            } else {
                // Debug: Failure detected
                $this->debugQueueProcessing($queueItem, $response, 'failure_detected');
                
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

                    // Log retry no vindi.log
                    $this->vindiLogger->execute('Multi-payment queue item failed, will retry', 'multi-payment-queue');
                    $this->vindiLogger->execute([
                        'queue_item_id' => $queueItem->getId(),
                        'error' => $errorMessage,
                        'attempt' => $queueItem->getAttempts(),
                        'max_attempts' => $queueItem->getMaxAttempts(),
                        'response' => $response
                    ], 'multi-payment-queue');
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

                    // Log falha permanente no vindi.log
                    $this->vindiLogger->execute('Multi-payment queue item failed permanently', 'multi-payment-queue');
                    $this->vindiLogger->execute([
                        'queue_item_id' => $queueItem->getId(),
                        'error' => $errorMessage,
                        'final_attempt' => $queueItem->getAttempts(),
                        'response' => $response
                    ], 'multi-payment-queue');
                }
            }

        } catch (\Exception $e) {
            $errorMessage = 'Exception during processing: ' . $e->getMessage();
            
            // Log exception no vindi.log
            $this->vindiLogger->execute('Exception during multi-payment queue processing', 'multi-payment-queue');
            $this->vindiLogger->execute([
                'queue_item_id' => $queueItem->getId(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'multi-payment-queue');
            
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
            // Log request usando o sistema padrão da API (para vindi.log e BD)
            $this->api->logRequest($requestData, 'multi-payment-cron');
            
            // Log request detalhado no vindi.log
            $this->vindiLogger->execute('Sending multi-payment API request', 'multi-payment-queue');
            $this->vindiLogger->execute([
                'queue_item_id' => $queueItem->getId(),
                'secondary_method' => $queueItem->getSecondaryMethodType(),
                'request_data' => $requestData
            ], 'multi-payment-queue');

            // Use the existing API Create client
            $apiResponse = $this->apiCreate->execute($requestData);
            
            // Log response usando o sistema padrão da API (para vindi.log e BD)
            $this->api->logResponse($apiResponse, 'multi-payment-cron');
            
            // Salvar request/response no BD como as transações normais
            $statusCode = $apiResponse['status'] ?? 200;
            $responseData = $apiResponse['response'] ?? $apiResponse;
            $this->api->saveRequest(
                $requestData, 
                $responseData, 
                $statusCode, 
                'multi-payment-' . $queueItem->getSecondaryMethodType()
            );
            
            $this->logger->debug('Secondary payment API response', [
                'queue_item_id' => $queueItem->getId(),
                'status_code' => $statusCode,
                'response' => $apiResponse
            ]);

            // Log response detalhado no vindi.log  
            $this->vindiLogger->execute('Multi-payment API response', 'multi-payment-queue');
            $this->vindiLogger->execute([
                'queue_item_id' => $queueItem->getId(),
                'status_code' => $statusCode,
                'full_response' => $apiResponse
            ], 'multi-payment-queue');

            return $responseData;

        } catch (\Exception $e) {
            $this->logger->error('Error making secondary payment API request', [
                'queue_item_id' => $queueItem->getId(),
                'error' => $e->getMessage()
            ]);

            // Log error no vindi.log
            $this->vindiLogger->execute('Error in multi-payment API request', 'multi-payment-queue');
            $this->vindiLogger->execute([
                'queue_item_id' => $queueItem->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $requestData
            ], 'multi-payment-queue');

            // Salvar erro no BD também
            $this->api->saveRequest(
                $requestData, 
                ['error' => true, 'message' => $e->getMessage()], 
                500, 
                'multi-payment-' . $queueItem->getSecondaryMethodType() . '-error'
            );

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
     * Check if response indicates success based on payment method type
     *
     * @param array $response
     * @param string $secondaryMethodType
     * @return bool
     */
    private function isSuccessfulResponse(array $response, string $secondaryMethodType): bool
    {
        // Log para debug da verificação
        $this->vindiLogger->execute('Checking response success', 'multi-payment-queue');
        $this->vindiLogger->execute([
            'secondary_method' => $secondaryMethodType,
            'response_structure' => array_keys($response),
            'data_response_keys' => isset($response['data_response']) ? array_keys($response['data_response']) : 'not_found',
            'transaction_keys' => isset($response['data_response']['transaction']) ? array_keys($response['data_response']['transaction']) : 'not_found',
            'payment_keys' => isset($response['data_response']['transaction']['payment']) ? array_keys($response['data_response']['transaction']['payment']) : 'not_found'
        ], 'multi-payment-queue');

        // Check for explicit error
        if (isset($response['error']) && $response['error']) {
            $this->vindiLogger->execute('Response contains explicit error flag', 'multi-payment-queue');
            return false;
        }

        // Check success based on payment method type
        switch ($secondaryMethodType) {
            case MultiPaymentQueue::SECONDARY_METHOD_PIX:
                return $this->isPixResponseSuccessful($response);
                
            case MultiPaymentQueue::SECONDARY_METHOD_BOLEPIX:
                return $this->isBolepixResponseSuccessful($response);
                
            case MultiPaymentQueue::SECONDARY_METHOD_CARD:
            case MultiPaymentQueue::SECONDARY_METHOD_CARD2:
                return $this->isCardResponseSuccessful($response);
                
            case MultiPaymentQueue::SECONDARY_METHOD_BANKSLIP:
                return $this->isBankslipResponseSuccessful($response);
                
            default:
                // Fallback to original logic with updated status locations
                $statusId = $response['data_response']['transaction']['status_id'] ?? 
                           $response['transaction']['status_id'] ?? 
                           $response['status_id'] ?? null;
                $isSuccess = in_array($statusId, [1, 4, 6, 11]); // Added status 4 for "Aguardando Pagamento"
                
                $this->vindiLogger->execute('Using fallback status verification', 'multi-payment-queue');
                $this->vindiLogger->execute([
                    'status_id' => $statusId,
                    'is_success' => $isSuccess
                ], 'multi-payment-queue');
                
                return $isSuccess;
        }
    }

    /**
     * Check if PIX response is successful
     *
     * @param array $response
     * @return bool
     */
    private function isPixResponseSuccessful(array $response): bool
    {
        // Check for PIX data in the actual API structure
        $pixCode = $response['data_response']['transaction']['payment']['qrcode_original_path'] ?? 
                   $response['qrcode_original_path'] ?? 
                   $response['pix_code'] ?? null;
                   
        $pixUrl = $response['data_response']['transaction']['payment']['url_payment'] ?? 
                  $response['url_payment'] ?? 
                  $response['pix_url'] ?? null;
                  
        $qrCodePath = $response['data_response']['transaction']['payment']['qrcode_path'] ?? 
                      $response['qrcode_path'] ?? null;
        
        $hasPixCode = !empty($pixCode);
        $hasPixUrl = !empty($pixUrl);
        $hasQrCode = !empty($qrCodePath);
        
        // For PIX, having either qrcode_original_path OR url_payment is sufficient
        $hasPixData = $hasPixCode || $hasPixUrl || $hasQrCode;
        
        // Check status in the actual API structure - PIX aguardando pagamento (status 4) é válido
        $statusId = $response['data_response']['transaction']['status_id'] ?? 
                   $response['transaction']['status_id'] ?? 
                   $response['status_id'] ?? null;
        
        // PIX valid statuses: 1=captured, 4=aguardando_pagamento, 6=authorized, 11=pending_capture
        $hasValidStatus = in_array($statusId, [1, 4, 6, 11]);
        
        $isSuccess = $hasPixData && $hasValidStatus;
        
        $this->vindiLogger->execute('PIX response validation', 'multi-payment-queue');
        $this->vindiLogger->execute([
            'pix_code_present' => $hasPixCode,
            'pix_url_present' => $hasPixUrl,
            'qr_code_present' => $hasQrCode,
            'has_pix_data' => $hasPixData,
            'status_id' => $statusId,
            'has_valid_status' => $hasValidStatus,
            'is_success' => $isSuccess,
            'pix_code_field' => !empty($pixCode) ? 'found' : 'not_found',
            'pix_url_field' => !empty($pixUrl) ? 'found' : 'not_found',
            'qr_code_field' => !empty($qrCodePath) ? 'found' : 'not_found'
        ], 'multi-payment-queue');
        
        return $isSuccess;
    }

    /**
     * Check if Bolepix response is successful
     *
     * @param array $response
     * @return bool
     */
    private function isBolepixResponseSuccessful(array $response): bool
    {
        // Check for Bolepix data in the actual API structure
        $bankslipUrl = $response['data_response']['transaction']['payment']['bankslip_url'] ?? 
                      $response['bankslip_url'] ?? null;
                      
        $pixCode = $response['data_response']['transaction']['payment']['qrcode_original_path'] ?? 
                  $response['qrcode_original_path'] ?? 
                  $response['pix_code'] ?? null;
                  
        $pixUrl = $response['data_response']['transaction']['payment']['url_payment'] ?? 
                 $response['url_payment'] ?? 
                 $response['pix_url'] ?? null;
        
        $hasBankslipUrl = !empty($bankslipUrl);
        $hasPixCode = !empty($pixCode);
        $hasPixUrl = !empty($pixUrl);
        
        // Check status in the actual API structure
        $statusId = $response['data_response']['transaction']['status_id'] ?? 
                   $response['transaction']['status_id'] ?? 
                   $response['status_id'] ?? null;
        
        // Bolepix valid statuses: 1=captured, 4=aguardando_pagamento, 6=authorized, 11=pending_capture
        $hasValidStatus = in_array($statusId, [1, 4, 6, 11]);
        
        // For Bolepix, we need either bankslip URL or PIX data (or both)
        $isSuccess = ($hasBankslipUrl || $hasPixCode || $hasPixUrl) && $hasValidStatus;
        
        $this->vindiLogger->execute('Bolepix response validation', 'multi-payment-queue');
        $this->vindiLogger->execute([
            'has_bankslip_url' => $hasBankslipUrl,
            'has_pix_code' => $hasPixCode,
            'has_pix_url' => $hasPixUrl,
            'status_id' => $statusId,
            'has_valid_status' => $hasValidStatus,
            'is_success' => $isSuccess
        ], 'multi-payment-queue');
        
        return $isSuccess;
    }

    /**
     * Check if Card response is successful
     *
     * @param array $response
     * @return bool
     */
    private function isCardResponseSuccessful(array $response): bool
    {
        // Check status in the actual API structure
        $statusId = $response['data_response']['transaction']['status_id'] ?? 
                   $response['transaction']['status_id'] ?? 
                   $response['status_id'] ?? null;
                   
        // Check transaction ID in various possible locations
        $transactionId = $response['data_response']['transaction']['transaction_id'] ?? 
                        $response['transaction_id'] ?? 
                        $response['tid'] ?? 
                        $response['transaction']['id'] ?? null;
        
        $hasTransactionId = !empty($transactionId);
        
        // Card valid statuses: 1=captured, 6=authorized, 11=pending_capture
        $isSuccess = in_array($statusId, [1, 6, 11]) && $hasTransactionId;
        
        $this->vindiLogger->execute('Card response validation', 'multi-payment-queue');
        $this->vindiLogger->execute([
            'status_id' => $statusId,
            'transaction_id' => $transactionId,
            'has_transaction_id' => $hasTransactionId,
            'is_success' => $isSuccess
        ], 'multi-payment-queue');
        
        return $isSuccess;
    }

    /**
     * Check if Bankslip response is successful
     *
     * @param array $response
     * @return bool
     */
    private function isBankslipResponseSuccessful(array $response): bool
    {
        // Check for Bankslip data in the actual API structure
        $bankslipUrl = $response['data_response']['transaction']['payment']['bankslip_url'] ?? 
                      $response['bankslip_url'] ?? null;
                      
        $bankslipCode = $response['data_response']['transaction']['payment']['bankslip_code'] ?? 
                       $response['bankslip_code'] ?? 
                       $response['linha_digitavel'] ?? null;
        
        $hasBankslipUrl = !empty($bankslipUrl);
        $hasBankslipCode = !empty($bankslipCode);
        
        // Check status in the actual API structure
        $statusId = $response['data_response']['transaction']['status_id'] ?? 
                   $response['transaction']['status_id'] ?? 
                   $response['status_id'] ?? null;
        
        // Bankslip valid statuses: 1=captured, 4=aguardando_pagamento, 6=authorized, 11=pending_capture
        $hasValidStatus = in_array($statusId, [1, 4, 6, 11]);
        
        $isSuccess = $hasBankslipUrl && $hasValidStatus;
        
        $this->vindiLogger->execute('Bankslip response validation', 'multi-payment-queue');
        $this->vindiLogger->execute([
            'has_bankslip_url' => $hasBankslipUrl,
            'has_bankslip_code' => $hasBankslipCode,
            'status_id' => $statusId,
            'has_valid_status' => $hasValidStatus,
            'is_success' => $isSuccess
        ], 'multi-payment-queue');
        
        return $isSuccess;
    }

    /**
     * Get response summary for logging
     *
     * @param array $response
     * @param string $secondaryMethodType
     * @return array
     */
    private function getResponseSummary(array $response, string $secondaryMethodType): array
    {
        $summary = [
            'secondary_method' => $secondaryMethodType,
            'has_error' => isset($response['error']) && $response['error'],
            'status_id' => $response['data_response']['transaction']['status_id'] ?? 
                          $response['transaction']['status_id'] ?? 
                          $response['status_id'] ?? 'not_found'
        ];

        switch ($secondaryMethodType) {
            case MultiPaymentQueue::SECONDARY_METHOD_PIX:
                $pixCode = $response['data_response']['transaction']['payment']['qrcode_original_path'] ?? 
                          $response['qrcode_original_path'] ?? 
                          $response['pix_code'] ?? null;
                $pixUrl = $response['data_response']['transaction']['payment']['url_payment'] ?? 
                         $response['url_payment'] ?? 
                         $response['pix_url'] ?? null;
                $qrCodePath = $response['data_response']['transaction']['payment']['qrcode_path'] ?? 
                             $response['qrcode_path'] ?? null;
                             
                $summary['pix_code_present'] = !empty($pixCode);
                $summary['pix_url_present'] = !empty($pixUrl);
                $summary['qr_code_present'] = !empty($qrCodePath);
                break;
                
            case MultiPaymentQueue::SECONDARY_METHOD_BOLEPIX:
                $bankslipUrl = $response['data_response']['transaction']['payment']['bankslip_url'] ?? 
                              $response['bankslip_url'] ?? null;
                $pixCode = $response['data_response']['transaction']['payment']['qrcode_original_path'] ?? 
                          $response['qrcode_original_path'] ?? 
                          $response['pix_code'] ?? null;
                          
                $summary['bankslip_url_present'] = !empty($bankslipUrl);
                $summary['pix_code_present'] = !empty($pixCode);
                break;
                
            case MultiPaymentQueue::SECONDARY_METHOD_CARD:
            case MultiPaymentQueue::SECONDARY_METHOD_CARD2:
                $transactionId = $response['data_response']['transaction']['transaction_id'] ?? 
                                $response['transaction_id'] ?? 
                                $response['tid'] ?? 'N/A';
                $summary['transaction_id'] = $transactionId;
                break;
                
            case MultiPaymentQueue::SECONDARY_METHOD_BANKSLIP:
                $bankslipUrl = $response['data_response']['transaction']['payment']['bankslip_url'] ?? 
                              $response['bankslip_url'] ?? null;
                $bankslipCode = $response['data_response']['transaction']['payment']['bankslip_code'] ?? 
                               $response['bankslip_code'] ?? null;
                               
                $summary['bankslip_url_present'] = !empty($bankslipUrl);
                $summary['bankslip_code_present'] = !empty($bankslipCode);
                break;
        }

        return $summary;
    }

    /**
     * Extract error message from response
     *
     * @param array $response
     * @return string
     */
    private function extractErrorMessage(array $response): string
    {
        // Check various possible error message locations including new API structure
        $errorSources = [
            'message',
            'error_message', 
            'status_reason',
            'error_description',
            'errors',
            'message_response.message',
            'data_response.message',
            'data_response.error_message',
            'transaction.error_message',
            'transaction.status_reason'
        ];

        foreach ($errorSources as $source) {
            if (strpos($source, '.') !== false) {
                // Handle nested properties like 'data_response.error_message'
                $parts = explode('.', $source);
                $value = $response;
                foreach ($parts as $part) {
                    if (isset($value[$part])) {
                        $value = $value[$part];
                    } else {
                        $value = null;
                        break;
                    }
                }
                if (!empty($value)) {
                    return is_array($value) ? implode(', ', $value) : (string)$value;
                }
            } else {
                if (!empty($response[$source])) {
                    $value = $response[$source];
                    return is_array($value) ? implode(', ', $value) : (string)$value;
                }
            }
        }

        // If no specific error message found, try to extract from status in new structure
        $statusId = $response['data_response']['transaction']['status_id'] ?? 
                   $response['transaction']['status_id'] ?? 
                   $response['status_id'] ?? null;
                   
        $statusName = $response['data_response']['transaction']['status_name'] ?? null;
        
        if ($statusId && !in_array($statusId, [1, 4, 6, 11])) {
            $statusText = $statusName ? " ({$statusName})" : '';
            return "Payment failed with status ID: {$statusId}{$statusText}";
        }

        // Check if it's a success case but validation failed (missing required fields)
        if ($statusId == 4 && isset($response['data_response']['transaction'])) {
            return "PIX payment created but missing required fields for validation";
        }

        return 'Unknown error occurred';
    }

    /**
     * Debug helper for multi-payment queue processing
     *
     * @param MultiPaymentQueue $queueItem
     * @param array $response
     * @param string $step
     * @return void
     */
    private function debugQueueProcessing(MultiPaymentQueue $queueItem, array $response, string $step): void
    {
        $debugData = [
            'step' => $step,
            'queue_item_id' => $queueItem->getId(),
            'order_id' => $queueItem->getOrderId(),
            'increment_id' => $queueItem->getIncrementId(),
            'secondary_method' => $queueItem->getSecondaryMethodType(),
            'current_status' => $queueItem->getStatus(),
            'attempts' => $queueItem->getAttempts(),
            'response_keys' => array_keys($response),
            'has_error_flag' => isset($response['error']) ? $response['error'] : 'not_set',
            'status_id_locations' => [
                'data_response_transaction' => $response['data_response']['transaction']['status_id'] ?? 'not_found',
                'transaction' => $response['transaction']['status_id'] ?? 'not_found',
                'direct' => $response['status_id'] ?? 'not_found'
            ]
        ];

        // Específico por tipo de método
        switch ($queueItem->getSecondaryMethodType()) {
            case MultiPaymentQueue::SECONDARY_METHOD_PIX:
                $debugData['pix_indicators'] = [
                    'qrcode_original_path' => !empty($response['data_response']['transaction']['payment']['qrcode_original_path']),
                    'url_payment' => !empty($response['data_response']['transaction']['payment']['url_payment']),
                    'qrcode_path' => !empty($response['data_response']['transaction']['payment']['qrcode_path']),
                    'fallback_pix_code' => !empty($response['pix_code']),
                    'fallback_pix_url' => !empty($response['pix_url'])
                ];
                break;
                
            case MultiPaymentQueue::SECONDARY_METHOD_BOLEPIX:
                $debugData['bolepix_indicators'] = [
                    'bankslip_url' => !empty($response['data_response']['transaction']['payment']['bankslip_url']),
                    'qrcode_original_path' => !empty($response['data_response']['transaction']['payment']['qrcode_original_path']),
                    'url_payment' => !empty($response['data_response']['transaction']['payment']['url_payment']),
                    'fallback_bankslip_url' => !empty($response['bankslip_url']),
                    'fallback_pix_code' => !empty($response['pix_code'])
                ];
                break;
        }

        $this->vindiLogger->execute("DEBUG - Multi-payment processing: {$step}", 'multi-payment-debug');
        $this->vindiLogger->execute($debugData, 'multi-payment-debug');
    }
}
