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

namespace Vindi\VP\Gateway\Request\CardPix;

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
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Vindi\VP\Model\ResourceModel\CreditCard\CollectionFactory as CreditCardCollectionFactory;
use Vindi\VP\Model\MultiPaymentQueueService;
use Vindi\VP\Model\MultiPaymentQueue;

/**
 * Class TransactionRequest
 * Handles the Card + Pix transaction request building
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
     * @var CreditCardCollectionFactory
     */
    protected $creditCardCollectionFactory;

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
     * @param CreditCardCollectionFactory $creditCardCollectionFactory
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
        CreditCardCollectionFactory $creditCardCollectionFactory,
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
        $this->creditCardCollectionFactory = $creditCardCollectionFactory;
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
     * Builds the CardPix primary request (Card only) and queues PIX for later processing
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

        $amountCredit = (float)($payment->getAdditionalInformation('amount_credit') ?? 0);
        $amountPix = (float)($payment->getAdditionalInformation('amount_pix') ?? 0);

        if ($amountCredit <= 0 && $amountPix <= 0) {
            $grandTotal = (float)$order->getGrandTotal();
            $amountCredit = round($grandTotal / 2, 2);
            $amountPix = $grandTotal - $amountCredit;
        }

        $this->logger->info('CardPix Transaction Build - Order: ' . $order->getIncrementId() . 
                     ', Total: ' . $order->getGrandTotal() . 
                     ', Subtotal: ' . $order->getBaseSubtotal() .
                     ', Shipping: ' . $order->getShippingAmount() .
                     ', Discount: ' . $order->getDiscountAmount() .
                     ', Credit: ' . $amountCredit . 
                     ', Pix: ' . $amountPix);

        $cardRequest = $this->buildPrimaryCardRequest($order, $payment, $amountCredit);

        $this->queuePixPayment($order, $payment, $amountPix);

        return [
            'request' => $cardRequest,
            'client_config' => [
                'store_id' => (int)$order->getStoreId(),
                'amount_pix' => $amountPix,
                'increment_id' => $order->getIncrementId()
            ]
        ];
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
        $transaction = $this->getTransaction($order, $amountCredit);

        $orderNumber = $order->getIncrementId() . '-01';
        $transaction['transaction']['order_number'] = $orderNumber;

        $this->logger->info('CardPix Primary Transaction - Order Number: ' . $orderNumber . ', Amount: ' . $amountCredit);

        $paymentProfileId = $payment->getAdditionalInformation('payment_profile');
        if ($paymentProfileId) {
            $transaction['payment'] = $this->getSavedCardData((string)$paymentProfileId, $payment);
        } else {
            $transaction['payment'] = $this->getNewCardData($payment);
        }

        return $transaction;
    }

    /**
     * Build the credit card portion of the request
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountCredit
     * @param float $amountPix
     * @param float $shipping
     * @param float $discount
     * @return array
     */
    private function buildCardRequest($order, $payment, float $amountCredit, float $amountPix, float $shipping, float $discount): array
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

    /**
     * Build the PIX portion of the request
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountCredit
     * @param float $amountPix
     * @param float $shipping
     * @param float $discount
     * @return array
     */
    private function buildPixRequest($order, $payment, float $amountCredit, float $amountPix, float $shipping, float $discount): array
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
     * Retrieves saved credit card data
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
     * Retrieves new credit card data
     *
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return array
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
     * Queue PIX payment for later processing
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountPix
     * @return void
     */
    private function queuePixPayment($order, $payment, float $amountPix): void
    {
        if ($amountPix <= 0) {
            return;
        }

        $pixRequestData = $this->buildPixRequestData($order, $payment, $amountPix);

        $queueData = [
            'increment_id' => (string)$order->getIncrementId(),
            'payment_method' => 'vindi_vp_cardpix',
            'secondary_method_type' => MultiPaymentQueue::SECONDARY_METHOD_PIX,
            'secondary_amount' => $amountPix,
            'request_data' => $pixRequestData,
            'status' => MultiPaymentQueue::STATUS_PENDING
        ];

        $this->eventManager->dispatch('vindi_vp_process_multi_payment_queue', [
            'order' => $order,
            'queue_data' => $queueData
        ]);

        $this->logger->info(
            "CardPix - Custom event 'vindi_vp_process_multi_payment_queue' dispatched for order {$order->getIncrementId()} with PIX amount: {$amountPix}",
            ['increment_id' => $order->getIncrementId(), 'amount_pix' => $amountPix]
        );
    }

    /**
     * Build PIX request data for queue processing
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountPix
     * @return array
     */
    private function buildPixRequestData($order, $payment, float $amountPix): array
    {
        $pixOrderNumber = $order->getIncrementId() . '-02';

        return [
            'token_account' => $this->helper->getToken($order->getStoreId()),
            'finger_print' => $payment->getAdditionalInformation('finger_print'),
            'customer' => $this->getCustomerData($order),
            'transaction' => [
                'customer_ip' => $order->getRemoteIp() ?: '127.0.0.1',
                'order_number' => $pixOrderNumber,
                'shipping_type' => $order->getShippingDescription() ?: 'SEM_FRETE',
                'shipping_price' => '0',
                'price_discount' => '0',
                'price_additional' => '0',
                'url_notification' => $this->helper->getPaymentsNotificationUrl($order),
                'free' => 'MAGENTO_API_' . $this->helper->getModuleVersion()
            ],
            'transaction_product' => $this->getPixItemsData($order, $amountPix),
            'payment' => [
                'payment_method_id' => $this->helper->getMethodId('PIX'),
                'split' => 1
            ]
        ];
    }

    /**
     * Get items data for PIX request with proportional amounts
     *
     * @param \Magento\Sales\Model\Order $order
     * @param float $pixAmount
     * @return array
     */
    private function getPixItemsData($order, float $pixAmount): array
    {
        $items = [];
        $quoteItems = $order->getAllItems();
        
        $proportion = 1.0;
        if ($order->getBaseSubtotal() > 0) {
            $proportion = $pixAmount / $order->getBaseSubtotal();
        }

        foreach ($quoteItems as $quoteItem) {
            if ($quoteItem->getParentItemId() || $quoteItem->getParentItem() || $quoteItem->getPrice() == 0) {
                continue;
            }

            $priceUnit = round($quoteItem->getPrice() * $proportion, 2);
            
            $items[] = [
                'description' => $quoteItem->getName(),
                'quantity' => (string) $quoteItem->getQtyOrdered(),
                'price_unit' => (string) $priceUnit,
                'code' => $quoteItem->getProductId(),
                'sku_code' => $quoteItem->getSku(),
                'extra' => $quoteItem->getItemId()
            ];
        }

        return $items;
    }
}
