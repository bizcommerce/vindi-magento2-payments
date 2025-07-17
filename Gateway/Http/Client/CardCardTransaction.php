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

            $transactionData = null;
            $storeId = null;

            if (isset($request['request'])) {
                $transactionData = $request['request'];
                $storeId = $request['client_config']['store_id'] ?? null;
            } elseif (isset($request['token_account']) && isset($request['payment'])) {
                $transactionData = $request;
                $storeId = $request['store_id'] ?? null;
            } else {
                $error = ['error' => 'Invalid request structure - no valid transaction data found'];
                $this->api->logResponse($error, self::LOG_NAME);

                return [
                    'error' => true,
                    'message' => 'Invalid request structure - no valid transaction data found'
                ];
            }

            $card1Response = $this->processCard1Payment($transactionData, $storeId);
            $this->api->logResponse($card1Response, self::LOG_NAME);

            $this->logger->debug([
                'message' => 'CardCard API Response structure',
                'has_data_response' => isset($card1Response['data_response']),
                'has_transaction' => isset($card1Response['data_response']['transaction']),
                'has_payment' => isset($card1Response['data_response']['transaction']['payment']),
                'has_tid' => isset($card1Response['data_response']['transaction']['payment']['tid']),
                'tid_value' => $card1Response['data_response']['transaction']['payment']['tid'] ?? 'NOT_SET',
                'status_id' => $card1Response['data_response']['transaction']['status_id'] ?? 'NOT_SET',
                'response_keys' => array_keys($card1Response)
            ]);            if ($this->isSuccessfulCard1Response($card1Response)) {
                $this->api->saveRequest($request, $card1Response, $card1Response['status'] ?? 'success', $this->methodCode);

                $transaction = $card1Response['data_response']['transaction'] ?? $card1Response;
                
                return [
                    'status' => 200,
                    'status_code' => 200,
                    'transaction' => [
                        'data_response' => [
                            'transaction' => $transaction
                        ]
                    ]
                ];
            }
            $this->api->saveRequest($request, $card1Response, $card1Response['status'] ?? 'error', $this->methodCode);

            $transaction = $card1Response['data_response']['transaction'] ?? $card1Response;

            return [
                'status' => 400,
                'status_code' => 400,
                'transaction' => [
                    'data_response' => [
                        'transaction' => $transaction
                    ]
                ]
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
        $this->logger->debug([
            'message' => 'CardCard isSuccessfulCard1Response - Checking response',
            'has_error' => isset($response['error']),
            'error_value' => $response['error'] ?? null,
            'response_keys' => array_keys($response)
        ]);

        if (isset($response['error'])) {
            $this->logger->debug([
                'message' => 'CardCard isSuccessfulCard1Response - Found error in response',
                'error' => $response['error']
            ]);
            return false;
        }

        if (isset($response['data_response']['transaction'])) {
            $transaction = $response['data_response']['transaction'];
            $statusId = $transaction['status_id'] ?? null;
            $tid = $transaction['payment']['tid'] ?? null;

            $this->logger->debug([
                'message' => 'CardCard isSuccessfulCard1Response - Checking data_response.transaction',
                'status_id' => $statusId,
                'has_tid' => !empty($tid),
                'tid_value' => $tid
            ]);

            if (in_array($statusId, ['3', '4', 3, 4])) {
                if ($statusId == '4' || $statusId == 4) {
                    $this->logger->debug(['message' => 'CardCard isSuccessfulCard1Response - Status 4 SUCCESS']);
                    return true;
                } elseif ($statusId == '3' || $statusId == 3) {
                    $success = !empty($tid);
                    $this->logger->debug([
                        'message' => 'CardCard isSuccessfulCard1Response - Status 3',
                        'has_valid_tid' => $success,
                        'tid' => $tid
                    ]);
                    return $success;
                }
            }
        }

        if (isset($response['status_id'])) {
            $statusId = $response['status_id'];
            $tid = $response['payment']['tid'] ?? null;

            $this->logger->debug([
                'message' => 'CardCard isSuccessfulCard1Response - Checking direct status_id',
                'status_id' => $statusId,
                'has_tid' => !empty($tid),
                'tid_value' => $tid
            ]);

            if (in_array($statusId, ['3', '4', 3, 4])) {
                if ($statusId == '4' || $statusId == 4) {
                    $this->logger->debug(['message' => 'CardCard isSuccessfulCard1Response - Direct Status 4 SUCCESS']);
                    return true;
                } elseif ($statusId == '3' || $statusId == 3) {
                    $success = !empty($tid);
                    $this->logger->debug([
                        'message' => 'CardCard isSuccessfulCard1Response - Direct Status 3',
                        'has_valid_tid' => $success,
                        'tid' => $tid
                    ]);
                    return $success;
                }
            }
        }

        $this->logger->debug(['message' => 'CardCard isSuccessfulCard1Response - FAILURE - No valid status found']);
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
        if (isset($response['error'])) {
            return false;
        }

        if (isset($response['data_response']['transaction']['status_id'])) {
            return in_array($response['data_response']['transaction']['status_id'], ['3', '4']);
        }

        if (isset($response['status_id'])) {
            return in_array($response['status_id'], ['3', '4']);
        }

        return false;
    }

}
