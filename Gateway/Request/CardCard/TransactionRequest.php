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

namespace Vindi\VP\Gateway\Request\CardCard;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Vindi\VP\Gateway\Http\Client\Api;
use Vindi\VP\Helper\Data;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Vindi\VP\Gateway\Request\PaymentsRequest;
use Vindi\VP\Model\MultiPaymentQueueService;
use Vindi\VP\Model\MultiPaymentQueue;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\ObjectManager;
use Vindi\VP\Model\ResourceModel\CreditCard\CollectionFactory as CreditCardCollectionFactory;

/**
 * Class TransactionRequest
 * Handles the Card + Card transaction request building
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
     * @var CreditCardCollectionFactory
     */
    protected $creditCardCollectionFactory;

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
     * @param CreditCardCollectionFactory $creditCardCollectionFactory
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
        MultiPaymentQueueService $multiPaymentQueueService,
        CreditCardCollectionFactory $creditCardCollectionFactory
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
        $this->creditCardCollectionFactory = $creditCardCollectionFactory;

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
     * Builds the CardCard primary request (Card1 only) and queues Card2 for later processing
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

        // Valores de split vindos do frontend
        $amountCard1 = (float)($payment->getAdditionalInformation('amount_card1') ?? 0);
        $amountCard2 = (float)($payment->getAdditionalInformation('amount_card2') ?? 0);

        // Se não vierem valores, dividir meio a meio como fallback
        if ($amountCard1 <= 0 && $amountCard2 <= 0) {
            $grandTotal = (float)$order->getGrandTotal();
            $amountCard1 = round($grandTotal / 2, 2);
            $amountCard2 = $grandTotal - $amountCard1;
        }

        // Log para debug dos valores
        $this->logger->info('CardCard Transaction Build - Order: ' . $order->getIncrementId() .
                     ', Total: ' . $order->getGrandTotal() .
                     ', Subtotal: ' . $order->getBaseSubtotal() .
                     ', Shipping: ' . $order->getShippingAmount() .
                     ', Discount: ' . $order->getDiscountAmount() .
                     ', Card1: ' . $amountCard1 .
                     ', Card2: ' . $amountCard2);

        // Construir apenas a requisição do primeiro cartão (primeira transação)
        $card1Request = $this->buildPrimaryCard1Request($order, $payment, $amountCard1);

        // Salvar o registro do segundo cartão na queue logo após criar a requisição do primeiro cartão
        $this->queueCard2Payment($order, $payment, $amountCard2);

        return [
            'request' => $card1Request,
            'client_config' => [
                'store_id' => (int)$order->getStoreId(),
                'amount_card2' => $amountCard2, // Para ser usado no response handler
                'increment_id' => $order->getIncrementId()
            ]
        ];
    }

    /**
     * Build the first credit card portion of the request
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountCard1
     * @param float $shipping
     * @param float $discount
     * @return array
     */
    private function buildCard1Request($order, $payment, float $amountCard1, float $shipping, float $discount): array
    {
        // Get the base transaction request
        $transaction = $this->getTransaction($order, $amountCard1);

        // Apply discount for the second card portion
        $transaction['transaction']['price_discount'] = (string)$discount;
        $transaction['transaction_shipping']['shipping_price'] = (string)$shipping;

        // Add credit card payment data
        $paymentProfileId = $payment->getAdditionalInformation('payment_profile');
        if ($paymentProfileId) {
            $transaction['payment'] = $this->getSavedCardData((string)$paymentProfileId, $payment);
        } else {
            $transaction['payment'] = $this->getNewCardData($payment);
        }

        // Set specific information in transaction for the first card
        $transaction['transaction']['order_number'] = $order->getIncrementId() . '-CARD1';

        return $transaction;
    }

    /**
     * Build the second credit card portion of the request
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountCard2
     * @param float $shipping
     * @param float $discount
     * @return array
     */
    private function buildCard2Request($order, $payment, float $amountCard2, float $shipping, float $discount): array
    {
        // Get the base transaction request
        $transaction = $this->getTransaction($order, $amountCard2);

        // Apply discount for the first card portion
        $transaction['transaction']['price_discount'] = (string)$discount;
        $transaction['transaction_shipping']['shipping_price'] = (string)$shipping;

        // Add second credit card payment data
        $paymentProfileId2 = $payment->getAdditionalInformation('payment_profile_2');
        if ($paymentProfileId2) {
            $transaction['payment'] = $this->getSavedSecondCardData((string)$paymentProfileId2, $payment);
        } else {
            $transaction['payment'] = $this->getNewSecondCardData($payment);
        }

        // Set specific information in transaction for the second card
        $transaction['transaction']['order_number'] = $order->getIncrementId() . '-CARD2';

        return $transaction;
    }

    /**
     * Retrieves saved first credit card data
     *
     * @param string $paymentProfileId
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getSavedCardData(string $paymentProfileId, $payment): array
    {
        $customerId = $this->customerSession->getCustomerId();

        if (!$customerId) {
            throw new LocalizedException(__('Customer is not logged in.'));
        }

        $creditCardResource = $this->creditCardCollectionFactory
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
            'card_cvv'           => $cvv,
            'split'              => (string)$installments
        ];
    }

    /**
     * Retrieves saved second credit card data
     *
     * @param string $paymentProfileId
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getSavedSecondCardData(string $paymentProfileId, $payment): array
    {
        $customerId = $this->customerSession->getCustomerId();

        if (!$customerId) {
            throw new LocalizedException(__('Customer is not logged in.'));
        }

        $creditCardResource = $this->creditCardCollectionFactory
            ->create()
            ->addFieldToFilter('entity_id', $paymentProfileId)
            ->addFieldToFilter('customer_id', $customerId)
            ->getFirstItem();

        if (!$creditCardResource->getId()) {
            throw new LocalizedException(__('Saved second card not found or does not belong to the current customer.'));
        }

        $cvv = $payment->getAdditionalInformation('cc_cid_2') ?: '';

        // CVV é sempre obrigatório para todos os cartões
        if (!$cvv || trim($cvv) === '') {
            throw new LocalizedException(__('CVV is required for second card.'));
        }

        $order = $payment->getOrder();
        $methodName = strtolower(str_replace(' ', '', (string)$creditCardResource->getCcType()));
        $installments = $payment->getAdditionalInformation('installments_2') ?: 1;

        return [
            'card_token'         => $creditCardResource->getCardToken(),
            'payment_method_id'  => $this->helper->getMethodIdByName($methodName),
            'card_cvv'           => $cvv,
            'split'              => (string)$installments
        ];
    }

    /**
     * Retrieves new first credit card data
     *
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return array
     */
    private function getNewCardData($payment): array
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
            'card_cvv'           => $ccCid,
            'split'              => (string)($installments ?: 1)
        ];

        if ($fingerprint) {
            $cardData['fingerprint'] = $fingerprint;
        }

        return $cardData;
    }

    /**
     * Retrieves new second credit card data
     *
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return array
     */
    private function getNewSecondCardData($payment): array
    {
        $order = $payment->getOrder();
        $saveCard = $payment->getAdditionalInformation('save_card_2');

        $ccType = $payment->getAdditionalInformation('cc_type_2') ?? '';
        $ccOwner = $payment->getAdditionalInformation('cc_owner_2') ?? '';
        $ccNumber = $payment->getAdditionalInformation('cc_number_2') ?? '';
        $ccLast4 = $payment->getAdditionalInformation('cc_last_4_2') ?? (is_string($ccNumber) ? substr($ccNumber, -4) : '');
        $ccExpMonth = $payment->getAdditionalInformation('cc_exp_month_2') ?? '';
        $ccExpYear = $payment->getAdditionalInformation('cc_exp_year_2') ?? '';
        $ccCid = $payment->getAdditionalInformation('cc_cid_2') ?? '';
        $installments = $payment->getAdditionalInformation('installments_2') ?? 1;
        $fingerprint = $payment->getAdditionalInformation('fingerprint_2');

        if ($saveCard) {
            $encryptedData = $this->encryptor->encrypt(json_encode([
                'cc_last_4' => $ccLast4,
                'cc_exp_date' => $ccExpMonth . '/' . $ccExpYear,
                'cc_name' => $ccOwner
            ]));
            $this->session->setData('encrypted_card_info_2', $encryptedData);
        }

        $cardData = [
            'payment_method_id'  => $this->helper->getMethodId((string)$ccType),
            'card_name'          => $ccOwner,
            'card_number'        => $ccNumber,
            'card_expdate_month' => $ccExpMonth,
            'card_expdate_year'  => $ccExpYear,
            'card_cvv'           => $ccCid,
            'split'              => (string)($installments ?: 1)
        ];

        if ($fingerprint) {
            $cardData['fingerprint'] = $fingerprint;
        }

        return $cardData;
    }

    /**
     * Build only the primary card1 request (first transaction)
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountCard1
     * @return array
     */
    private function buildPrimaryCard1Request($order, $payment, float $amountCard1): array
    {
        // Get the base transaction request for the card1 amount only
        $transaction = $this->getTransaction($order, $amountCard1);

        // Override order_number with increment_id-01 format for card1 payment
        $orderNumber = $order->getIncrementId() . '-01';
        $transaction['transaction']['order_number'] = $orderNumber;

        // Log para confirmar o order_number
        $this->logger->info('CardCard Primary Transaction - Order Number: ' . $orderNumber . ', Amount: ' . $amountCard1);

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
     * Queue Card2 payment for later processing
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountCard2
     * @return void
     */
    private function queueCard2Payment($order, $payment, float $amountCard2): void
    {
        if ($amountCard2 <= 0) {
            return; // No Card2 amount to process
        }

        // Build Card2 request data
        $card2RequestData = $this->buildCard2RequestData($order, $payment, $amountCard2);

        // Store Card2 queue data in payment additional information for later processing
        // This will be processed after the order is saved via observer
        $card2QueueData = [
            'increment_id' => (string)$order->getIncrementId(),
            'payment_method' => 'vindi_vp_cardcard',
            'secondary_method_type' => MultiPaymentQueue::SECONDARY_METHOD_CARD2,
            'secondary_amount' => $amountCard2,
            'request_data' => $card2RequestData,
            'status' => MultiPaymentQueue::STATUS_PENDING
        ];

        $payment->setAdditionalInformation('card2_queue_data', $card2QueueData);

        // Log the queue operation
        $this->logger->info(
            "CardCard - Card2 payment data prepared for queue for order {$order->getIncrementId()} with amount: {$amountCard2}",
            ['increment_id' => $order->getIncrementId(), 'amount_card2' => $amountCard2]
        );
    }

    /**
     * Build Card2 request data for queue
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountCard2
     * @return array
     */
    private function buildCard2RequestData($order, $payment, float $amountCard2): array
    {
        // Get the base transaction request for the Card2 amount
        $transaction = $this->getTransaction($order, $amountCard2);

        // Override order_number with increment_id-02 format for Card2 payment
        $transaction['transaction']['order_number'] = $order->getIncrementId() . '-02';

        // Add second credit card payment data
        $paymentProfileId2 = $payment->getAdditionalInformation('payment_profile_2');
        if ($paymentProfileId2) {
            $transaction['payment'] = $this->getSavedSecondCardData((string)$paymentProfileId2, $payment);
        } else {
            $transaction['payment'] = $this->getNewSecondCardData($payment);
        }

        return $transaction;
    }
}
