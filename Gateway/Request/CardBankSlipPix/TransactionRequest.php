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

namespace Vindi\VP\Gateway\Request\CardBankSlipPix;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\ObjectManager;
use Vindi\VP\Model\ResourceModel\CreditCard\CollectionFactory as CreditCardCollectionFactory;
use Vindi\VP\Gateway\Request\PaymentsRequest;
use Vindi\VP\Helper\Data;
use Vindi\VP\Gateway\Http\Client\Api;
use Vindi\VP\Model\MultiPaymentQueueService;
use Vindi\VP\Model\MultiPaymentQueue;
use Psr\Log\LoggerInterface;
use function __;

/**
 * Class TransactionRequest
 * Handles the Card + Bolepix transaction request building
 */
class TransactionRequest extends PaymentsRequest implements BuilderInterface
{
    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var SessionManagerInterface
     */
    protected $session;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var MultiPaymentQueueService
     */
    protected $multiPaymentQueueService;

    /**
     * TransactionRequest constructor.
     *
     * @param ManagerInterface $eventManager
     * @param Data $helper
     * @param DateTime $date
     * @param ConfigInterface $config
     * @param CustomerSession $customerSession
     * @param DateTime $dateTime
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param Api $api
     * @param EncryptorInterface $encryptor
     * @param SessionManagerInterface $session
     * @param LoggerInterface $logger
     * @param MultiPaymentQueueService $multiPaymentQueueService
     */
    public function __construct(
        ManagerInterface $eventManager,
        Data $helper,
        DateTime $date,
        ConfigInterface $config,
        CustomerSession $customerSession,
        DateTime $dateTime,
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        Api $api,
        EncryptorInterface $encryptor,
        SessionManagerInterface $session,
        LoggerInterface $logger,
        MultiPaymentQueueService $multiPaymentQueueService
    ) {
        $this->eventManager = $eventManager;
        $this->helper = $helper;
        $this->date = $date;
        $this->config = $config;
        $this->customerSession = $customerSession;
        $this->dateTime = $dateTime;
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->api = $api;
        $this->encryptor = $encryptor;
        $this->session = $session;
        $this->logger = $logger;
        $this->multiPaymentQueueService = $multiPaymentQueueService;

        parent::__construct(
            $eventManager,
            $helper,
            $date,
            $config,
            $customerSession,
            $dateTime,
            $productRepository,
            $categoryRepository,
            $api
        );
    }

    /**
     * Builds the CardBankSlipPix primary request (Card only) and queues BankSlip and PIX for later processing
     *
     * @param array $buildSubject
     * @return array
     * @throws \InvalidArgumentException
     */
    public function build(array $buildSubject): array
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        $payment = $buildSubject['payment']->getPayment();
        $order = $payment->getOrder();

        // Valores de split vindos do frontend - apenas dois valores
        $amountCredit = (float)($payment->getAdditionalInformation('amount_credit') ?? 0);
        $amountBolepix = (float)($payment->getAdditionalInformation('amount_bolepix') ?? 0);

        // Se não vierem valores, dividir igualmente como fallback
        if ($amountCredit <= 0 && $amountBolepix <= 0) {
            $grandTotal = (float)$order->getGrandTotal();
            $amountCredit = round($grandTotal / 2, 2);
            $amountBolepix = $grandTotal - $amountCredit; // Resto para evitar diferenças de centavos
        }

        // Log para debug dos valores
        $this->logger->info('CardBankSlipPix Transaction Build - Order: ' . $order->getIncrementId() .
            ', Card: ' . $amountCredit . ', Bolepix: ' . $amountBolepix);

        // Construir apenas a requisição do cartão (primeira transação)
        $cardRequest = $this->buildPrimaryCardRequest($order, $payment, $amountCredit);

        // Salvar dados temporariamente no payment para serem processados após o salvamento da order
        $this->queueBolepixPayment($order, $payment, $amountBolepix);

        return [
            'request' => $cardRequest,
            'client_config' => [
                'store_id' => (int)$order->getStoreId(),
                'increment_id' => $order->getIncrementId()
            ]
        ];
    }

    private function buildCardRequest($order, $payment, float $amountCredit, float $shipping, float $discount): array
    {
        $transaction = $this->getTransaction($order, $amountCredit);
        $transaction['transaction']['price_discount'] = (string)$discount;
        $transaction['transaction']['shipping_price'] = (string)$shipping;
        $paymentProfileId = $payment->getAdditionalInformation('payment_profile');
        if ($paymentProfileId) {
            $transaction['payment'] = $this->getSavedCardData((string)$paymentProfileId, $payment);
        } else {
            $transaction['payment'] = $this->getNewCardData($payment);
        }
        return $transaction;
    }

    private function buildBankSlipRequest($order, $payment, float $amountBankSlip, float $shipping, float $discount): array
    {
        $transaction = $this->getTransaction($order, $amountBankSlip);
        $transaction['transaction']['price_discount'] = (string)$discount;
        $transaction['transaction']['shipping_price'] = (string)$shipping;
        $transaction['payment'] = [
            'payment_method_id' => $this->helper->getMethodId('BANK_SLIP'),
            'split' => 1
        ];
        $transaction['transaction']['order_number'] = $order->getIncrementId() . '-BANKSLIP';
        return $transaction;
    }

    private function buildPixRequest($order, $payment, float $amountPix, float $shipping, float $discount): array
    {
        $transaction = $this->getTransaction($order, $amountPix);
        $transaction['transaction']['price_discount'] = (string)$discount;
        $transaction['transaction']['shipping_price'] = (string)$shipping;
        $transaction['payment'] = [
            'payment_method_id' => $this->helper->getMethodId('PIX'),
            'split' => 1
        ];
        $transaction['transaction']['order_number'] = $order->getIncrementId() . '-PIX';
        return $transaction;
    }

    /**
     * Recupera dados de cartão salvo
     */
    protected function getSavedCardData(string $paymentProfileId, $payment): array
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            throw new LocalizedException(__('Customer is not logged in.'));
        }
        $creditCardResource = ObjectManager::getInstance()
            ->get(CreditCardCollectionFactory::class)
            ->create()
            ->addFieldToFilter('entity_id', $paymentProfileId)
            ->addFieldToFilter('customer_id', $customerId)
            ->getFirstItem();
        if (!$creditCardResource->getId()) {
            throw new LocalizedException(__('Saved card not found or does not belong to the current customer.'));
        }
        $cvv = $payment->getAdditionalInformation('cc_cid') ?: $payment->getCcCid();

        // CVV é sempre obrigatório para todos os cartões
        if (!$cvv || trim($cvv) === '') {
            throw new LocalizedException(__('CVV is required for all cards.'));
        }
        $order = $payment->getOrder();
        $methodName = strtolower(str_replace(' ', '', (string)$creditCardResource->getCcType()));
        $installments = $payment->getAdditionalInformation('installments') ?: 1;
        return [
            'card_token'         => $creditCardResource->getCardToken(),
            'payment_method_id'  => $this->helper->getMethodIdByName($methodName),
            'split'              => (string)$installments
        ];
    }

    /**
     * Recupera dados de novo cartão
     */
    protected function getNewCardData($payment): array
    {
        $order = $payment->getOrder();
        $saveCard = $payment->getAdditionalInformation('save_card');
        $ccType = $payment->getAdditionalInformation('cc_type') ?? $payment->getCcType() ?? '';
        $ccOwner = $payment->getAdditionalInformation('cc_owner') ?? $payment->getCcOwner() ?? '';
        $ccNumber = $payment->getAdditionalInformation('cc_number') ?? $payment->getCcNumber() ?? '';
        $ccLast4 = $payment->getAdditionalInformation('cc_last_4') ?? $payment->getCcLast4() ?? (is_string($ccNumber) ? substr($ccNumber, -4) : '');
        $ccExpMonth = $payment->getAdditionalInformation('cc_exp_month') ?? $payment->getCcExpMonth() ?? '';
        $ccExpYear = $payment->getAdditionalInformation('cc_exp_year') ?? $payment->getCcExpYear() ?? '';
        $ccCid = $payment->getAdditionalInformation('cc_cid') ?? $payment->getCcCid() ?? '';
        $installments = $payment->getAdditionalInformation('installments') ?? 1;
        $fingerprint = $payment->getAdditionalInformation('fingerprint');
        if ($saveCard) {
            $encryptedData = $this->encryptor->encrypt(json_encode([
                'cc_last_4' => $ccLast4,
                'cc_exp_date' => $ccExpMonth . '/' . $ccExpYear,
                'cc_name' => $ccOwner
            ]));
            $this->session->setData('encrypted_card_info', $encryptedData);
        }
        $cardData = [
            'payment_method_id'  => $this->helper->getMethodId((string)$ccType),
            'card_name'          => $ccOwner,
            'card_number'        => $ccNumber,
            'card_expdate_month' => $ccExpMonth,
            'card_expdate_year'  => $ccExpYear,
            'split'              => (string)($installments ?: 1)
        ];
        if ($fingerprint) {
            $cardData['fingerprint'] = $fingerprint;
        }
        return $cardData;
    }

    /**
     * Build only the primary card request (first transaction)
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountCredit
     * @return array
     */
    private function buildPrimaryCardRequest($order, $payment, float $amountCredit): array
    {
        // Get the base transaction request for the card amount only
        $transaction = $this->getTransaction($order, $amountCredit);

        // Override order_number with increment_id-01 format for card payment
        $orderNumber = $order->getIncrementId() . '-01';
        $transaction['transaction']['order_number'] = $orderNumber;

        // Log para confirmar o order_number
        $this->logger->info('CardBankSlipPix Primary Transaction - Order Number: ' . $orderNumber . ', Amount: ' . $amountCredit);

        // Add credit card payment data
        $paymentProfileId = $payment->getAdditionalInformation('payment_profile');
        if ($paymentProfileId) {
            $transaction['payment'] = $this->getSavedCardData((string)$paymentProfileId, $payment);
        } else {
            $transaction['payment'] = $this->getNewCardData($payment);
        }

        return $transaction;
    }

    /**
     * Queue Bolepix payment for later processing
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountBolepix
     * @return void
     */
    private function queueBolepixPayment($order, $payment, float $amountBolepix): void
    {
        if ($amountBolepix <= 0) {
            return;
        }

        // Build Bolepix request data
        $bolepixRequestData = $this->buildBolepixRequestData($order, $payment, $amountBolepix);

        // Prepare queue data for the event
        $queueData = [
            'increment_id' => (string)$order->getIncrementId(),
            'payment_method' => 'vindi_vp_cardbankslippix',
            'secondary_method_type' => MultiPaymentQueue::SECONDARY_METHOD_BOLEPIX,
            'secondary_amount' => $amountBolepix,
            'request_data' => $bolepixRequestData,
            'status' => MultiPaymentQueue::STATUS_PENDING
        ];

        // Dispatch custom event to process multi-payment queue immediately
        $this->eventManager->dispatch('vindi_vp_process_multi_payment_queue', [
            'order' => $order,
            'queue_data' => $queueData
        ]);

        $this->logger->info(
            "CardBankSlipPix - Custom event 'vindi_vp_process_multi_payment_queue' dispatched for order {$order->getIncrementId()} with Bolepix amount: {$amountBolepix}",
            ['increment_id' => $order->getIncrementId(), 'amount_bolepix' => $amountBolepix]
        );
    }

    /**
     * Build Bolepix request data for queue
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountBolepix
     * @return array
     */
    private function buildBolepixRequestData($order, $payment, float $amountBolepix): array
    {
        // Get the base transaction request for the Bolepix amount
        $transaction = $this->getTransaction($order, $amountBolepix);

        // Override order_number with increment_id-02 format for Bolepix payment
        $transaction['transaction']['order_number'] = $order->getIncrementId() . '-02';

        // Set Bolepix payment method (combines bankslip and pix functionality)
        $transaction['payment'] = [
            'payment_method_id' => $this->helper->getMethodId('BANK_SLIP'), // Use BANK_SLIP as base method for Bolepix
            'split' => 1
        ];

        return $transaction;
    }
}
