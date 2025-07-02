<?php

declare(strict_types=1);

namespace Vindi\VP\Model\Webhook;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Framework\DB\Transaction;

class MultiPaymentHandler
{
    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var CreditmemoService
     */
    private $creditmemoService;

    /**
     * @var Transaction
     */
    private $transaction;

    public function __construct(
        InvoiceService $invoiceService,
        CreditmemoService $creditmemoService,
        Transaction $transaction
    ) {
        $this->invoiceService = $invoiceService;
        $this->creditmemoService = $creditmemoService;
        $this->transaction = $transaction;
    }

    /**
     * @param Order $order
     * @param array $webhookData
     * @return void
     */
    public function processSuccess(Order $order, array $webhookData): void
    {
        // Lógica para o Fluxo 1: Pagamento Confirmado
        // 1. Validar se a fatura para esta parte já existe
        // 2. Criar fatura parcial online
        // 3. Adicionar comentário ao pedido
        // 4. Verificar se o pedido está completo e mudar status para "processing"
    }

    /**
     * @param Order $order
     * @param array $webhookData
     * @return void
     */
    public function processFailure(Order $order, array $webhookData): void
    {
        // Lógica para o Fluxo 2: Pagamento Negado/Cancelado
        // 1. Verificar se já existe fatura
        // 2. Se sim, criar credit memo (refund)
        // 3. Cancelar o pedido
        // 4. Adicionar comentários apropriados
    }
}
