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

class Refund extends Client
{
    /**
     *
     * @param mixed $data
     * @param int|null $storeId
     * @return array
     */
    public function execute($data, $storeId = null)
    {
        return $this->refund($data, $storeId);
    }

    /**
     *
     * @param mixed $data
     * @param int|null $storeId
     * @return array
     */
    public function cancel($data, $storeId)
    {
        return $this->refund($data, $storeId);
    }

    /**
     *
     * @param mixed $data
     * @param int|null $storeId
     * @return array
     */
    public function refund($data, $storeId)
    {
        $path = $this->getEndpointPath('payments/refund');
        $method = Request::METHOD_PATCH;
        return $this->makeRequest($path, $method, 'payments', $data, $storeId);
    }
}
