<?php
namespace Vindi\VP\Cron;

use Vindi\VP\Model\ResourceModel\PixQueue\CollectionFactory as PixQueueCollectionFactory;
use Vindi\VP\Model\PixQueueFactory;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;
use Vindi\VP\Helper\Order as HelperOrder;
use GuzzleHttp\Client as HttpClient;

class ProcessPixQueue
{
    const MAX_ATTEMPTS = 5;

    protected $pixQueueCollectionFactory;
    protected $pixQueueFactory;
    protected $orderFactory;
    protected $logger;
    protected $helperOrder;
    protected $httpClient;

    public function __construct(
        PixQueueCollectionFactory $pixQueueCollectionFactory,
        PixQueueFactory $pixQueueFactory,
        OrderFactory $orderFactory,
        LoggerInterface $logger,
        HelperOrder $helperOrder,
        HttpClient $httpClient
    ) {
        $this->pixQueueCollectionFactory = $pixQueueCollectionFactory;
        $this->pixQueueFactory = $pixQueueFactory;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->helperOrder = $helperOrder;
        $this->httpClient = $httpClient;
    }

    public function execute()
    {
        $collection = $this->pixQueueCollectionFactory->create();
        $collection->addFieldToFilter('status', 'pending');
        $collection->addFieldToFilter('attempts', ['lt' => self::MAX_ATTEMPTS]);
        $collection->setPageSize(10);

        foreach ($collection as $pixQueue) {
            try {
                $pixQueue->setStatus('processing');
                $pixQueue->setAttempts($pixQueue->getAttempts() + 1);
                $pixQueue->save();

                // Aqui você deve implementar a chamada ao PSP Pix
                // Exemplo fictício:
                $meta = json_decode($pixQueue->getPaymentMeta(), true);
                $pixResult = $this->sendPixToPsp($pixQueue->getAmountPix(), $meta);

                if ($pixResult['success']) {
                    $pixQueue->setStatus('done');
                    $pixQueue->save();
                    $this->finalizeOrder($pixQueue->getOrderId());
                } else {
                    if ($pixQueue->getAttempts() >= self::MAX_ATTEMPTS) {
                        $pixQueue->setStatus('failed');
                        $this->cancelOrderAndRefund($pixQueue->getOrderId(), $pixQueue->getPaymentIdCc());
                    } else {
                        $pixQueue->setStatus('pending');
                    }
                    $pixQueue->save();
                }
            } catch (\Exception $e) {
                $this->logger->error('[PixQueue] Erro ao processar fila: ' . $e->getMessage());
                $pixQueue->setStatus('pending');
                $pixQueue->save();
            }
        }
    }

    private function sendPixToPsp($amountPix, $meta)
    {
        // Exemplo de chamada real ao PSP Pix
        try {
            $endpoint = $meta['psp_endpoint'] ?? 'https://psp.exemplo.com/pix';
            $apiKey = $meta['psp_api_key'] ?? '';
            $payload = [
                'amount' => $amountPix,
                'pix_key' => $meta['pix_key'] ?? '',
                'order_id' => $meta['order_id'] ?? '',
                'expiration' => $meta['expiration'] ?? 3600
            ];
            $headers = [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ];
            $response = $this->httpClient->post($endpoint, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => 10
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
            if (isset($body['success']) && $body['success']) {
                return ['success' => true, 'data' => $body];
            }
            return ['success' => false, 'data' => $body];
        } catch (\Exception $e) {
            $this->logger->error('[PixQueue] Erro ao chamar PSP Pix: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function finalizeOrder($orderId)
    {
        $order = $this->orderFactory->create()->load($orderId);
        if ($order && $order->canInvoice()) {
            $this->helperOrder->captureOrder($order, 'online');
        }
        // Enviar e-mail de confirmação de pedido
        if ($order && !$order->getEmailSent()) {
            try {
                $order->sendNewOrderEmail();
            } catch (\Exception $e) {
                $this->logger->error('[PixQueue] Falha ao enviar e-mail de confirmação: ' . $e->getMessage());
            }
        }
    }

    private function cancelOrderAndRefund($orderId, $paymentIdCc)
    {
        $order = $this->orderFactory->create()->load($orderId);
        if ($order && !$order->isCanceled()) {
            $grandTotal = (float)$order->getGrandTotal();
            $this->helperOrder->refundOrder($order, $grandTotal, false);
            $this->helperOrder->cancelOrder($order, $grandTotal, false);
        }
        // Opcional: adicionar comentário de histórico
    }
}
