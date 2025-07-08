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

use Laminas\Http\Client as HttpClient;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;
use Vindi\VP\Helper\Data;
use Vindi\VP\Model\AccessToken;

/**
 * Class CardBankSlipPixTransaction
 * Handles the transaction for BankSlip + Pix payment method
 */
class CardBankSlipPixTransaction implements ClientInterface
{
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
     * @var string
     */
    private $methodCode;

    /**
     * CardBankSlipPixTransaction constructor.
     *
     * @param Logger $logger
     * @param Json $json
     * @param Data $helper
     * @param AccessToken $accessToken
     * @param string $methodCode
     */
    public function __construct(
        Logger $logger,
        Json $json,
        Data $helper,
        AccessToken $accessToken,
        $methodCode = 'vindi_vp_cardbankslippix'
    ) {
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

        $cardResponse = $this->processCardPayment($request, $storeId);

        if ($this->isSuccessfulCardResponse($cardResponse)) {
            return [
                'success' => true,
                'transaction' => $cardResponse
            ];
        }

        return [
            'error' => true,
            'transaction' => $cardResponse
        ];
    }

    /**
     * Process the bankslip payment portion of the transaction
     *
     * @param array $request
     * @param int|null $storeId
     * @return array
     */
    private function processBankSlipPayment(array $request, ?int $storeId): array
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
     * Cancel a bank slip payment
     *
     * @param array $response
     * @param int|null $storeId
     * @return bool
     */
    private function cancelBankSlipPayment(array $response, ?int $storeId): bool
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

            $this->logger->debug([
                'method' => $this->methodCode,
                'bankslipCancel' => $cancelResponse
            ]);

            return isset($cancelResponse['status_id']) && $cancelResponse['status_id'] == '5';
        } catch (\Exception $e) {
            $this->logger->debug([
                'method' => $this->methodCode,
                'bankslipCancelError' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Checks if the bank slip response was successful
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulBankSlipResponse(array $response): bool
    {
        return !isset($response['error']) &&
            isset($response['status_id']) &&
            in_array($response['status_id'], ['3', '4']);
    }

    /**
     * Checks if the PIX response was successful
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulPixResponse(array $response): bool
    {
        return !isset($response['error']) &&
            isset($response['status_id']) &&
            in_array($response['status_id'], ['3', '4']);
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
     * Log the request data with sensitive information masked
     *
     * @param array $request
     * @return void
     */
    private function logRequest(array $request): void
    {
        $maskedRequest = $this->helper->maskSensitiveData($request);

        $this->logger->debug([
            'method' => $this->methodCode,
            'request' => $maskedRequest
        ]);
    }
}
