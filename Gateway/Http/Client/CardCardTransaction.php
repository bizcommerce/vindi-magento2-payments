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

namespace Vindi\VP\Gateway\Http\Client;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;
use Vindi\VP\Gateway\Http\Client\Api;
use Vindi\VP\Helper\Data;
use Vindi\VP\Model\AccessToken;

/**
 * Class CardCardTransaction
 * Handles the transaction for Card + Card payment method
 */
class CardCardTransaction implements ClientInterface
{
    public const LOG_NAME = 'vindi-cardcard';

    /**
     * @var Json
     */
    private $json;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var AccessToken
     */
    private $accessToken;

    /**
     * @var Api
     */
    private $api;

    /**
     * @var string
     */
    private $methodCode;

    /**
     * CardCardTransaction constructor.
     *
     * @param Logger $logger
     * @param Json $json
     * @param Data $helper
     * @param AccessToken $accessToken
     * @param Api $api
     * @param string $methodCode
     */
    public function __construct(
        Logger $logger,
        Json $json,
        Data $helper,
        AccessToken $accessToken,
        Api $api,
        $methodCode = 'vindi_vp_cardcard'
    ) {
        $this->logger = $logger;
        $this->json = $json;
        $this->helper = $helper;
        $this->accessToken = $accessToken;
        $this->api = $api;
        $this->methodCode = $methodCode;
    }

    /**
     * Places request to gateway
     *
     * @param TransferInterface $transferObject
     * @return array
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        try {
            $request = $transferObject->getBody();
            $this->api->logRequest($request, self::LOG_NAME);

            // Handle both old and new request structures
            $transactionData = null;
            $storeId = null;

            if (isset($request['request'])) {
                // New structure: ['request' => $transaction, 'client_config' => [...]]
                $transactionData = $request['request'];
                $storeId = $request['client_config']['store_id'] ?? null;
            } elseif (isset($request['token_account']) && isset($request['payment'])) {
                // Old structure: direct transaction data
                $transactionData = $request;
                // Try to extract store_id from other sources or use null
                $storeId = $request['store_id'] ?? null;
            } else {
                $error = ['error' => 'Invalid request structure - no valid transaction data found'];
                $this->api->logResponse($error, self::LOG_NAME);

                return [
                    'error' => true,
                    'message' => 'Invalid request structure - no valid transaction data found'
                ];
            }

            // Process only the first card payment (primary transaction)
            $card1Response = $this->processCard1Payment($transactionData, $storeId);
            $this->api->logResponse($card1Response, self::LOG_NAME);

            // Log detailed response structure for debugging
            $this->logger->debug("CardCard API Response structure: " . json_encode([
                'has_data_response' => isset($card1Response['data_response']),
                'has_transaction' => isset($card1Response['data_response']['transaction']),
                'has_payment' => isset($card1Response['data_response']['transaction']['payment']),
                'has_tid' => isset($card1Response['data_response']['transaction']['payment']['tid']),
                'tid_value' => $card1Response['data_response']['transaction']['payment']['tid'] ?? 'NOT_SET',
                'status_id' => $card1Response['data_response']['transaction']['status_id'] ?? 'NOT_SET',
                'response_keys' => array_keys($card1Response)
            ]), [], 'vindi-cardcard-debug');            if ($this->isSuccessfulCard1Response($card1Response)) {
                // Save to database
                $this->api->saveRequest($request, $card1Response, $card1Response['status'] ?? 'success', $this->methodCode);

                // Ensure the response has the expected structure for the TransactionHandler
                $normalizedResponse = $card1Response;
                if (isset($card1Response['data_response']['transaction'])) {
                    // The TransactionHandler expects the transaction data in response['transaction']
                    // So we put the transaction data directly there, preserving the original structure
                    $normalizedResponse = [
                        'transaction' => $card1Response['data_response']['transaction']
                    ];

                    // Also preserve any other fields that might be needed
                    $normalizedResponse['data_response'] = $card1Response['data_response'];
                    $normalizedResponse['message_response'] = $card1Response['message_response'] ?? '';
                }

                // Return structure compatible with gateway validator
                return [
                    'status' => 200,
                    'status_code' => 200,
                    'transaction' => $card1Response['data_response']['transaction'] ?? $normalizedResponse
                ];
            }            // First card payment failed
            // Save to database
            $this->api->saveRequest($request, $card1Response, $card1Response['status'] ?? 'error', $this->methodCode);

            // Ensure the response has the expected structure for the TransactionHandler
            $normalizedResponse = $card1Response;
            if (isset($card1Response['data_response']['transaction'])) {
                // The TransactionHandler expects the transaction data in response['transaction']
                // So we put the transaction data directly there, preserving the original structure
                $normalizedResponse = [
                    'transaction' => $card1Response['data_response']['transaction']
                ];

                // Also preserve any other fields that might be needed
                $normalizedResponse['data_response'] = $card1Response['data_response'];
                $normalizedResponse['message_response'] = $card1Response['message_response'] ?? '';
            }

            // Return structure compatible with gateway validator
            return [
                'status' => 400,
                'status_code' => 400,
                'transaction' => $card1Response['data_response']['transaction'] ?? $normalizedResponse
            ];

        } catch (\Exception $e) {
            $error = ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
            $this->api->logResponse($error, self::LOG_NAME);

            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Process the first card payment portion of the transaction
     *
     * @param array $request
     * @param int|null $storeId
     * @return array
     */
    private function processCard1Payment(array $request, ?int $storeId): array
    {
        try {
            $response = $this->helper->makeHttpRequest('api/v3/transactions/payment', $request, 'POST', $storeId);
            return $response;
        } catch (\Exception $e) {
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Process the second card payment portion of the transaction
     *
     * @param array $request
     * @param int|null $storeId
     * @return array
     */
    private function processCard2Payment(array $request, ?int $storeId): array
    {
        try {
            $response = $this->helper->makeHttpRequest('api/v3/transactions/payment', $request, 'POST', $storeId);
            return $response;
        } catch (\Exception $e) {
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Refund a card payment in case of error
     *
     * @param array $response
     * @param int|null $storeId
     * @return bool
     */
    private function refundCardPayment(array $response, ?int $storeId): bool
    {
        try {
            if (!isset($response['tid'])) {
                return false;
            }

            $cancelResponse = $this->helper->makeHttpRequest('api/v3/transactions/cancel', [], 'DELETE', $storeId);
            return isset($cancelResponse['status_id']) && $cancelResponse['status_id'] == '5';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if the first card response was successful
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulCard1Response(array $response): bool
    {
        // Check if response has error
        if (isset($response['error'])) {
            return false;
        }

        // Check if response has the expected structure
        if (isset($response['data_response']['transaction'])) {
            $transaction = $response['data_response']['transaction'];
            $statusId = $transaction['status_id'] ?? null;

            // Check if status_id indicates success (3 = approved, 4 = waiting payment)
            // Support both string and numeric values
            if (in_array($statusId, ['3', '4', 3, 4])) {
                if ($statusId == '4' || $statusId == 4) {
                    // Status 4 (Aguardando Pagamento) is considered successful
                    return true;
                } elseif ($statusId == '3' || $statusId == 3) {
                    // Status 3 (Aprovado) requires tid to be present and not empty
                    $tid = $transaction['payment']['tid'] ?? null;
                    return !empty($tid);
                }
            }
        }

        // Fallback: check old structure for backward compatibility
        if (isset($response['status_id'])) {
            $statusId = $response['status_id'];

            if (in_array($statusId, ['3', '4', 3, 4])) {
                if ($statusId == '4' || $statusId == 4) {
                    return true;
                } elseif ($statusId == '3' || $statusId == 3) {
                    $tid = $response['payment']['tid'] ?? null;
                    return !empty($tid);
                }
            }
        }

        return false;
    }

    /**
     * Checks if the card response was successful
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulCardResponse(array $response): bool
    {
        // Check if response has error
        if (isset($response['error'])) {
            return false;
        }

        // Check if response has the expected structure
        if (isset($response['data_response']['transaction']['status_id'])) {
            return in_array($response['data_response']['transaction']['status_id'], ['3', '4']);
        }

        // Fallback: check old structure for backward compatibility
        if (isset($response['status_id'])) {
            return in_array($response['status_id'], ['3', '4']);
        }

        return false;
    }

}
