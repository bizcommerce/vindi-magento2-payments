<?php
declare(strict_types=1);

namespace Vindi\VP\Controller\Callback;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;

class Payments extends \Vindi\VP\Controller\Callback
{
    /**
     * @var string
     */
    protected $eventName = 'pix';

    /**
     * Validate CSRF request.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $hash = $request->getParam('hash');
        $storeHash = sha1($this->helperData->getToken());
        return ($hash === $storeHash);
    }

    /**
     * Execute callback and register it in the callback table.
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $this->helperData->log(__('Webhook %1', __CLASS__), self::LOG_NAME);
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $statusCode = 200;

        try {
            $content = $this->getContent($this->getRequest()) ?? '';
            $params = $this->getRequest()->getParams();
            
            // === ADIÇÃO: Log extra para debugging ===
            if (empty($content) && !empty($params)) {
                $this->helperData->log('Content empty, using params instead', self::LOG_NAME);
            }
            
            $this->logParams($content, $params);

            if (isset($params['transaction'])) {
                // === ADIÇÃO: Validação extra para multi-payment ===
                $orderNumber = $params['transaction']['order_number'] ?? ($params['transaction']['free'] ?? '');
                if (preg_match('/(.*?)-(\d{2})$/', $orderNumber)) {
                    $this->helperData->log('Multi-payment webhook detected in controller: ' . $orderNumber, self::LOG_NAME);
                }
                
                $callBack = $this->callbackFactory->create();
                $callBack->setStatus($params['transaction']['status_name'] ?? '');
                $callBack->setMethod('vindi-payments');
                $callBack->setIncrementId($params['transaction']['order_number'] ?? ($params['transaction']['free'] ?? ''));
                $callBack->setPayload($this->json->serialize($params));
                $callBack->setQueueStatus('pending');
                
                // === ADIÇÃO: Log antes de salvar ===
                $this->helperData->log('Saving callback to queue: ' . ($params['transaction']['order_number'] ?? 'unknown'), self::LOG_NAME);
                
                $this->callbackResourceModel->save($callBack);
                
                // === ADIÇÃO: Log após salvar com sucesso ===
                $this->helperData->log('Callback saved successfully', self::LOG_NAME);
            }
        } catch (\Exception $e) {
            $statusCode = 500;
            $this->helperData->log($e->getMessage());
            
            // === ADIÇÃO: Log mais detalhado para debugging ===
            $this->helperData->log('Enhanced error details: ' . $e->getMessage(), self::LOG_NAME);
            $this->helperData->log('Error trace: ' . $e->getTraceAsString(), self::LOG_NAME);
        }

        $result->setHttpResponseCode($statusCode);
        return $result;
    }
}
