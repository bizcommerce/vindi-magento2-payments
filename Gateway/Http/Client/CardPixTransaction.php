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

use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;
use Vindi\VP\Helper\Data;
use Vindi\VP\Model\AccessToken;

/**
 * Class CardPixTransaction
 * Handles the transaction for Card + Pix payment method
 */
class CardPixTransaction implements ClientInterface
{
    /**
     * @var ZendClientFactory
     */
    private $clientFactory;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var AccessToken
     */
    private $accessToken;

    /**
     * @var string
     */
    private $methodCode;

    /**
     * CardPixTransaction constructor.
     *
     * @param ZendClientFactory $clientFactory
     * @param Logger $logger
     * @param Json $json
     * @param Data $helper
     * @param AccessToken $accessToken
     * @param string $methodCode
     */
    public function __construct(
        ZendClientFactory $clientFactory,
        Logger $logger,
        Json $json,
        Data $helper,
        AccessToken $accessToken,
        $methodCode = 'vindi_vp_cardpix'
    ) {
        $this->clientFactory = $clientFactory;
        $this->logger = $logger;
        $this->json = $json;
        $this->helper = $helper;
        $this->accessToken = $accessToken;
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
        $request = $transferObject->getBody();
        $this->logRequest($request);
        $storeId = $request['client_config']['store_id'] ?? null;

        // Process only the card payment (primary transaction)
        $cardResponse = $this->processCardPayment($request, $storeId);

        if ($this->isSuccessfulCardResponse($cardResponse)) {
            return [
                'success' => true,
                'transaction' => $cardResponse
            ];
        }

        // Card payment failed
        return [
            'error' => true,
            'transaction' => $cardResponse
        ];
    }

    /**
     * Process the card payment portion of the transaction
     *
     * @param array $request
     * @param int|null $storeId
     * @return array
     */
    private function processCardPayment(array $request, ?int $storeId): array
    {
        try {
            $url = $this->helper->getApiUrl('payments');
            $client = $this->clientFactory->create();

            $client->setUri($url);
            $client->setConfig(['maxredirects' => 0, 'timeout' => 45]);
            $client->setHeaders(['Content-Type: application/json']);
            $client->setHeaders('key', $this->helper->getPublicKey($storeId));

            $client->setRawData($this->json->serialize($request), 'application/json');
            $client->setMethod(\Zend_Http_Client::POST);

            $responseBody = $client->request()->getBody();
            $response = $this->json->unserialize($responseBody);

            // Log the response
            $this->logger->debug([
                'method' => $this->methodCode,
                'response' => $this->helper->maskSensitiveData($response)
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->logger->debug([
                'method' => $this->methodCode,
                'error' => $e->getMessage()
            ]);

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Process the PIX payment portion of the transaction
     *
     * @param array $request
     * @param int|null $storeId
     * @return array
     */
    private function processPixPayment(array $request, ?int $storeId): array
    {
        try {
            $url = $this->helper->getApiUrl('payments');
            $client = $this->clientFactory->create();

            $client->setUri($url);
            $client->setConfig(['maxredirects' => 0, 'timeout' => 45]);
            $client->setHeaders(['Content-Type: application/json']);
            $client->setHeaders('key', $this->helper->getPublicKey($storeId));

            $client->setRawData($this->json->serialize($request), 'application/json');
            $client->setMethod(\Zend_Http_Client::POST);

            $responseBody = $client->request()->getBody();
            $response = $this->json->unserialize($responseBody);

            // Log the response
            $this->logger->debug([
                'method' => $this->methodCode,
                'response' => $this->helper->maskSensitiveData($response)
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->logger->debug([
                'method' => $this->methodCode,
                'error' => $e->getMessage()
            ]);

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Refund a card payment in case the PIX payment fails
     *
     * @param array $cardResponse
     * @param int|null $storeId
     * @return array
     */
    private function refundCardPayment(array $cardResponse, ?int $storeId): array
    {
        try {
            if (!isset($cardResponse['payment']['tid'])) {
                return ['error' => true, 'message' => 'Missing TID for refund'];
            }

            $tid = $cardResponse['payment']['tid'];

            // Get access token
            $token = $this->accessToken->getToken($storeId);

            if (!$token) {
                return ['error' => true, 'message' => 'Could not get access token for refund'];
            }

            $url = $this->helper->getApiUrl('cancel') . '/' . $tid;
            $client = $this->clientFactory->create();

            $client->setUri($url);
            $client->setConfig(['maxredirects' => 0, 'timeout' => 45]);
            $client->setHeaders(['Content-Type: application/json']);
            $client->setHeaders('Authorization', 'Bearer ' . $token);

            $client->setMethod(\Zend_Http_Client::PUT);

            $responseBody = $client->request()->getBody();
            $response = $this->json->unserialize($responseBody);

            // Log the refund response
            $this->logger->debug([
                'method' => $this->methodCode,
                'refund_response' => $response
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->logger->debug([
                'method' => $this->methodCode,
                'refund_error' => $e->getMessage()
            ]);

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check if the card response was successful
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulCardResponse(array $response): bool
    {
        return isset($response['status_id']) &&
               in_array($response['status_id'], ['3', '4']) &&
               isset($response['payment']['tid']);
    }

    /**
     * Check if the PIX response was successful
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulPixResponse(array $response): bool
    {
        return isset($response['status_id']) &&
               in_array($response['status_id'], ['3', '4']) &&
               isset($response['payment']['tid']) &&
               isset($response['payment']['pix_code']);
    }

    /**
     * Log the request data with sensitive information masked
     *
     * @param array $request
     * @return void
     */
    private function logRequest(array $request): void
    {
        $cardRequest = $request['card_request'] ?? [];
        $pixRequest = $request['pix_request'] ?? [];

        // Mask sensitive information in card request
        if (isset($cardRequest['payment']['card_number'])) {
            $cardRequest['payment']['card_number'] = $this->helper->maskCardNumber(
                $cardRequest['payment']['card_number']
            );
        }

        if (isset($cardRequest['payment']['card_cvv'])) {
            $cardRequest['payment']['card_cvv'] = '***';
        }

        $this->logger->debug([
            'method' => $this->methodCode,
            'card_request' => $cardRequest,
            'pix_request' => $pixRequest
        ]);
    }
}
