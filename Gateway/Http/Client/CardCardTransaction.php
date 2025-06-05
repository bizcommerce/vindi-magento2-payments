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
 * Class CardCardTransaction
 * Handles the transaction for Card + Card payment method
 */
class CardCardTransaction implements ClientInterface
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
     * CardCardTransaction constructor.
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
        $methodCode = 'vindi_vp_cardcard'
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

        // Log the request data with sensitive information masked
        $this->logRequest($request);

        $storeId = $request['client_config']['store_id'] ?? null;

        // Process first card payment request
        $card1Response = $this->processCard1Payment($request['card1_request'], $storeId);

        // If first card payment was successful, process second card payment
        if ($this->isSuccessfulCardResponse($card1Response)) {
            $card2Response = $this->processCard2Payment($request['card2_request'], $storeId);

            // If second card payment failed, we need to refund the first card payment
            if (!$this->isSuccessfulCardResponse($card2Response)) {
                $this->refundCardPayment($card1Response, $storeId);
                return ['error' => true, 'card1_response' => $card1Response, 'card2_response' => $card2Response];
            }

            // Both transactions were successful
            return [
                'success' => true,
                'card1_response' => $card1Response,
                'card2_response' => $card2Response
            ];
        }

        // First card payment failed, return the error
        return ['error' => true, 'card1_response' => $card1Response];
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

            $url = $this->helper->getApiUrl(sprintf('payments/%s', $response['tid']));
            $client = $this->clientFactory->create();

            $client->setUri($url);
            $client->setConfig(['maxredirects' => 0, 'timeout' => 45]);
            $client->setHeaders(['Content-Type: application/json']);
            $client->setHeaders('key', $this->helper->getPublicKey($storeId));
            $client->setMethod(\Zend_Http_Client::DELETE);

            $responseBody = $client->request()->getBody();
            $cancelResponse = $this->json->unserialize($responseBody);

            // Log the response
            $this->logger->debug([
                'method' => $this->methodCode,
                'cardRefund' => $cancelResponse
            ]);

            return isset($cancelResponse['status_id']) && $cancelResponse['status_id'] == '5';
        } catch (\Exception $e) {
            $this->logger->debug([
                'method' => $this->methodCode,
                'cardRefundError' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Checks if the card response was successful
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulCardResponse(array $response): bool
    {
        return !isset($response['error']) &&
            isset($response['status_id']) &&
            in_array($response['status_id'], ['3', '4']);
    }

    /**
     * Log the request data with sensitive information masked
     *
     * @param array $request
     * @return void
     */
    private function logRequest(array $request): void
    {
        // Mask sensitive data before logging
        $maskedRequest = $this->helper->maskSensitiveData($request);

        $this->logger->debug([
            'method' => $this->methodCode,
            'request' => $maskedRequest
        ]);
    }
}
