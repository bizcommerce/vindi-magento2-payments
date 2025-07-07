<?php

/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Gateway\Http\Client\Api;

use Vindi\VP\Gateway\Http\Client;
use Laminas\Http\Request;

class Cancel extends Client
{
    /**
     * Cancel a transaction using Vindi API
     *
     * @param array $data
     * @param int|null $storeId
     * @return array
     */
    public function execute(array $data, $storeId = null): array
    {
        return $this->cancelTransaction($data, $storeId);
    }

    /**
     * Cancel transaction via PATCH /api/v3/transactions/cancel
     *
     * @param array $data
     * @param int|null $storeId
     * @return array
     */
    public function cancelTransaction(array $data, $storeId = null): array
    {
        $path = $this->getEndpointPath('payments/cancel');
        $method = 'PATCH';
        return $this->makeRequest($path, $method, 'payments', $data, $storeId);
    }

    /**
     * Cancel transaction with specific amount (partial refund)
     *
     * @param string $transactionId
     * @param string $accessToken
     * @param float|null $refundAmount
     * @param int|null $storeId
     * @return array
     */
    public function cancelWithAmount(
        string $transactionId,
        string $accessToken,
        ?float $refundAmount = null,
        $storeId = null
    ): array {
        $data = [
            'access_token' => $accessToken,
            'transaction_id' => $transactionId
        ];

        if ($refundAmount !== null) {
            $data['refund_amount'] = (string) $refundAmount;
        }

        return $this->cancelTransaction($data, $storeId);
    }
}
