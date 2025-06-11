<?php

/**
 *
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

class Query extends Client
{
    /**
     *
     * @param string $orderId
     * @param string $token
     * @param int|null $storeId
     * @return array
     */
    public function execute($orderId, $token, $storeId = null)
    {
        $path = $this->getEndpointPath('payments/get', $orderId, $token);
        $method = Request::METHOD_GET;
        return $this->makeRequest($path, $method, 'payments', [], $storeId);
    }
}
