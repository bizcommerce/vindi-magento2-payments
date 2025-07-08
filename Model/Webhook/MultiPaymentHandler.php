<?php

declare(strict_types=1);

namespace Vindi\VP\Model\Webhook;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Vindi\VP\Logger\Logger;
use Vindi\VP\Helper\Data as HelperData;

class MultiPaymentHandler
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var HelperData
     */
    private $helperData;

    public function __construct(
        Logger $logger,
        HelperData $helperData
    ) {
        $this->logger = $logger;
        $this->helperData = $helperData;
    }

    /**
     * @param Order $order
     * @param array $webhookData
     * @return void
     */
    public function processSuccess(Order $order, array $webhookData): void
    {
        try {
            $this->helperData->log('Processing multi-payment success for order ' . $order->getIncrementId());

            // Extrair informações do webhook
            $paymentAmount = (float)($webhookData['price_payment'] ?? 0);
            $paymentMethodName = $webhookData['payment_method_name'] ?? 'Unknown';
            $transactionId = $webhookData['transaction_id'] ?? '';

            /** @var Payment $payment */
            $payment = $order->getPayment();

            // Verificar se já existe invoice para este valor específico
            $existingInvoices = $order->getInvoiceCollection();
            $alreadyInvoiced = false;

            foreach ($existingInvoices as $invoice) {
                if (abs($invoice->getGrandTotal() - $paymentAmount) < 0.01) {
                    $this->helperData->log('Invoice already exists for amount ' . $paymentAmount);
                    $alreadyInvoiced = true;
                    break;
                }
            }

            if (!$alreadyInvoiced && $paymentAmount > 0) {
                // Criar invoice parcial para o valor do pagamento aprovado
                $this->createPartialInvoice($order, $paymentAmount, $paymentMethodName, $transactionId);
            }

            // Adicionar comentário sobre o pagamento parcial aprovado
            $comment = 'Multi-payment approved: ' . $paymentMethodName . ' - Amount: ' . $paymentAmount;
            $order->addCommentToStatusHistory($comment);

            // Atualizar informações adicionais do pagamento
            $multiPaymentInfo = $payment->getAdditionalInformation('multi_payment_info') ?: [];
            $multiPaymentInfo[] = [
                'method' => $paymentMethodName,
                'amount' => $paymentAmount,
                'transaction_id' => $transactionId,
                'status' => 'approved',
                'date' => date('Y-m-d H:i:s')
            ];
            $payment->setAdditionalInformation('multi_payment_info', $multiPaymentInfo);

            // Verificar se todos os pagamentos foram processados
            $this->checkOrderCompletion($order);

            // Salvar order manualmente
            $order->save();
            $this->helperData->log('Multi-payment success processed for order ' . $order->getIncrementId());

        } catch (\Exception $e) {
            $this->helperData->log('Error processing multi-payment success: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param Order $order
     * @param array $webhookData
     * @return void
     */
    public function processFailure(Order $order, array $webhookData): void
    {
        try {
            $this->helperData->log('Processing multi-payment failure for order ' . $order->getIncrementId());

            // Extrair informações do webhook
            $paymentAmount = (float)($webhookData['price_payment'] ?? 0);
            $paymentMethodName = $webhookData['payment_method_name'] ?? 'Unknown';
            $transactionId = $webhookData['transaction_id'] ?? '';

            /** @var Payment $payment */
            $payment = $order->getPayment();

            // Verificar se existem invoices para estornar
            $invoices = $order->getInvoiceCollection();
            if (count($invoices) > 0) {

                foreach ($invoices as $invoice) {
                    if ($invoice->canRefund()) {
                        try {
                            // Create refund for the existing invoice
                            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                            $creditmemoFactory = $objectManager->get(\Magento\Sales\Model\Order\CreditmemoFactory::class);
                            $creditmemoService = $objectManager->get(\Magento\Sales\Model\Service\CreditmemoService::class);

                            $creditmemo = $creditmemoFactory->createByInvoice($invoice);
                            $creditmemoService->refund($creditmemo);
                        } catch (\Exception $e) {
                            $this->helperData->log('Failed to refund invoice ' . $invoice->getIncrementId() . ': ' . $e->getMessage());
                        }
                    }
                }
            }

            // Adicionar comentário sobre o pagamento parcial negado
            $comment = 'Multi-payment failed: ' . $paymentMethodName . ' - Amount: ' . $paymentAmount;
            $order->addCommentToStatusHistory($comment);

            // Atualizar informações adicionais do pagamento
            $multiPaymentInfo = $payment->getAdditionalInformation('multi_payment_info') ?: [];
            $multiPaymentInfo[] = [
                'method' => $paymentMethodName,
                'amount' => $paymentAmount,
                'transaction_id' => $transactionId,
                'status' => 'failed',
                'date' => date('Y-m-d H:i:s')
            ];
            $payment->setAdditionalInformation('multi_payment_info', $multiPaymentInfo);

            // Verificar se devemos cancelar o pedido completamente
            $this->checkOrderCancellation($order);

            // Salvar order manualmente
            $order->save();
            $this->helperData->log('Multi-payment failure processed for order ' . $order->getIncrementId());

        } catch (\Exception $e) {
            $this->helperData->log('Error processing multi-payment failure: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a partial invoice for the order
     *
     * @param Order $order
     * @param float $amount
     * @param string $paymentMethod
     * @param string $transactionId
     * @return void
     */
    private function createPartialInvoice(Order $order, float $amount, string $paymentMethod, string $transactionId): void
    {
        try {
            if (!$order->canInvoice()) {
                $this->helperData->log('Cannot create invoice for order ' . $order->getIncrementId());
                return;
            }

            // Criar invoice usando o método que funciona no outro módulo Vindi
            $invoice = $order->prepareInvoice();

            // Para multi-pagamento, ajustar os valores proporcionalmente
            $orderTotal = $order->getGrandTotal();
            $ratio = $amount / $orderTotal;

            $this->helperData->log('Creating partial invoice - Order total: ' . $orderTotal . ', Payment amount: ' . $amount . ', Ratio: ' . $ratio);

            // Ajustar quantidade dos itens proporcionalmente
            foreach ($invoice->getAllItems() as $item) {
                $originalQty = $item->getQty();
                $newQty = $originalQty * $ratio;
                $item->setQty($newQty);
                $this->helperData->log('Item adjusted - Original qty: ' . $originalQty . ', New qty: ' . $newQty);
            }

            // Definir o valor total do invoice
            $invoice->setGrandTotal($amount);
            $invoice->setBaseGrandTotal($amount);

            // Configurar como captura offline (já pago)
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            $invoice->register();
            $invoice->pay();
            $invoice->setSendEmail(true);

            // Adicionar comentário sobre o multi-pagamento
            $invoice->addComment(
                'Multi-payment: ' . $paymentMethod . ' - Transaction ID: ' . $transactionId,
                false,
                false
            );

            // Salvar o invoice usando o repositório
            $this->saveInvoice($invoice);

            $this->helperData->log('Partial invoice created successfully for order ' . $order->getIncrementId() . ', amount ' . $amount);

        } catch (\Exception $e) {
            $this->helperData->log('Error creating partial invoice: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Save invoice using repository pattern (like the working Vindi module)
     *
     * @param \Magento\Sales\Model\Order\Invoice $invoice
     * @return void
     */
    private function saveInvoice(\Magento\Sales\Model\Order\Invoice $invoice): void
    {
        try {
            // Usar o ObjectManager para obter o InvoiceRepository (padrão do módulo que funciona)
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $invoiceRepository = $objectManager->get(\Magento\Sales\Api\InvoiceRepositoryInterface::class);

            $invoiceRepository->save($invoice);

            $this->helperData->log('Invoice saved successfully with ID: ' . $invoice->getId());

        } catch (\Exception $e) {
            $this->helperData->log('Failed to save invoice: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if order is complete and update status
     *
     * @param Order $order
     * @return void
     */
    private function checkOrderCompletion(Order $order): void
    {
        try {
            $totalInvoiced = 0;
            foreach ($order->getInvoiceCollection() as $invoice) {
                $totalInvoiced += $invoice->getGrandTotal();
            }

            // Se o valor total faturado é igual ao valor total do pedido
            if (abs($totalInvoiced - $order->getGrandTotal()) < 0.01) {
                $this->helperData->log('Order ' . $order->getIncrementId() . ' fully paid, updating status');

                $updateStatus = $order->getIsVirtual()
                    ? $this->helperData->getConfig('paid_virtual_order_status')
                    : $this->helperData->getConfig('paid_order_status');

                if ($updateStatus) {
                    $order->setStatus($updateStatus);
                    $order->addCommentToStatusHistory('Multi-payment completed - Order fully paid');
                }
            }
        } catch (\Exception $e) {
            $this->helperData->log('Error checking order completion: ' . $e->getMessage());
        }
    }

    /**
     * Check if order should be cancelled due to failed payments
     *
     * @param Order $order
     * @return void
     */
    private function checkOrderCancellation(Order $order): void
    {
        try {
            /** @var Payment $payment */
            $payment = $order->getPayment();
            $multiPaymentInfo = $payment->getAdditionalInformation('multi_payment_info') ?: [];

            $failedPayments = array_filter($multiPaymentInfo, function($info) {
                return $info['status'] === 'failed';
            });

            // Se todos os pagamentos falharam, cancelar o pedido
            if (count($failedPayments) === count($multiPaymentInfo) && count($multiPaymentInfo) > 0) {
                if ($order->canCancel()) {
                    $order->cancel();
                    $order->addCommentToStatusHistory('Order cancelled - All multi-payments failed');
                    $this->helperData->log('Order ' . $order->getIncrementId() . ' cancelled due to all multi-payments failing');
                }
            }
        } catch (\Exception $e) {
            $this->helperData->log('Error checking order cancellation: ' . $e->getMessage());
        }
    }
}
