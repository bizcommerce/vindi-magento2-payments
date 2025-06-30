<?php
/**
 *
 *
 *
 *
 *
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 *
 *
 */

namespace Vindi\VP\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

class TransferFactory implements TransferFactoryInterface
{
    /**
     * @var TransferBuilder
     */
    private $transferBuilder;

    /**
     * @param TransferBuilder $transferBuilder
     */
    public function __construct(
        TransferBuilder $transferBuilder
    ) {
        $this->transferBuilder = $transferBuilder;
    }

    /**
     * Builds gateway transfer object
     *
     * @param array $request
     * @return TransferInterface
     */
    public function create(array $request)
    {
        // Support for both new (request) and old (request_card) structures
        if (isset($request['request'])) {
            $this->transferBuilder->setBody($request['request']);
        } elseif (isset($request['request_card'])) {
            $this->transferBuilder->setBody($request['request_card']);
        } else {
            throw new \InvalidArgumentException('Request body not found (expected request or request_card)');
        }
        
        if (isset($request['client_config'])) {
            $this->transferBuilder->setClientConfig($request['client_config']);
        }
        
        return $this->transferBuilder->build();
    }
}
