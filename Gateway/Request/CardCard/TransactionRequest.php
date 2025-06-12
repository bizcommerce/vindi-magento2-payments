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
        SessionManagerInterface $session
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
     * Builds ENV request
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

        // Split vindos do frontend (garantir fallback)
        $grandTotal = (float)$order->getGrandTotal();
        $shipping = (float)$order->getShippingAmount();
        $discount = abs((float)$order->getDiscountAmount());

        $amountCard1 = (float)($payment->getAdditionalInformation('amount_card1') ?? 0);
        $amountCard2 = (float)($payment->getAdditionalInformation('amount_card2') ?? 0);

        // Fallback: se não vierem, dividir igualmente
        if ($amountCard1 <= 0 && $amountCard2 <= 0) {
            $amountCard1 = round($grandTotal / 2, 2);
            $amountCard2 = $grandTotal - $amountCard1;
        }

        // Proporção para shipping e desconto
        $totalSplit = $amountCard1 + $amountCard2;
        $shippingCard1 = $totalSplit > 0 ? round($shipping * ($amountCard1 / $totalSplit), 2) : 0;
        $shippingCard2 = $shipping - $shippingCard1;
        $discountCard1 = $totalSplit > 0 ? round($discount * ($amountCard1 / $totalSplit), 2) : 0;
        $discountCard2 = $discount - $discountCard1;

        // Montar requests separados
        $card1Request = $this->buildCard1Request($order, $payment, $amountCard1, $shippingCard1, $discountCard1);
        $card2Request = $this->buildCard2Request($order, $payment, $amountCard2, $shippingCard2, $discountCard2);

        return [
            'card1_request' => $card1Request,
            'card2_request' => $card2Request,
            'client_config' => ['store_id' => (int)$order->getStoreId()]
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

        $installments = $payment->getAdditionalInformation('installments') ?? 1;

        return [
            'payment_method_id' => $this->helper->getMethodId('CREDIT_CARD'),
            'card_id' => $paymentProfileId,
            'customer_id' => $customerId,
            'installments' => $installments,
            'split' => 1
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

        $installments = $payment->getAdditionalInformation('installments_2') ?? 1;

        return [
            'payment_method_id' => $this->helper->getMethodId('CREDIT_CARD'),
            'card_id' => $paymentProfileId,
            'customer_id' => $customerId,
            'installments' => $installments,
            'split' => 1
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
        $ccNumber = $payment->getAdditionalInformation('cc_number');
        $ccExpMonth = $payment->getAdditionalInformation('cc_exp_month');
        $ccExpYear = $payment->getAdditionalInformation('cc_exp_year');
        $ccCid = $payment->getAdditionalInformation('cc_cid');
        $ccType = $payment->getAdditionalInformation('cc_type');
        $ccOwner = $payment->getAdditionalInformation('cc_owner');
        $installments = $payment->getAdditionalInformation('installments') ?? 1;
        $saveCard = $payment->getAdditionalInformation('save_card') ?? 0;

        $fingerprint = $payment->getAdditionalInformation('fingerprint');

        return [
            'payment_method_id' => $this->helper->getMethodId('CREDIT_CARD'),
            'card_number' => preg_replace('/\D/', '', (string) $ccNumber),
            'card_holder' => $ccOwner,
            'card_expiration_date' => sprintf('%s%s', $ccExpMonth, $ccExpYear),
            'card_cvv' => $ccCid,
            'installments' => $installments,
            'card_brand' => $this->helper->getCardBrand($ccType),
            'save_card' => (bool) $saveCard,
            'fingerprint' => $fingerprint,
            'split' => 1
        ];
    }

    /**
     * Retrieves new second credit card data
     *
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return array
     */
    private function getNewSecondCardData($payment): array
    {
        $ccNumber = $payment->getAdditionalInformation('cc_number_2');
        $ccExpMonth = $payment->getAdditionalInformation('cc_exp_month_2');
        $ccExpYear = $payment->getAdditionalInformation('cc_exp_year_2');
        $ccCid = $payment->getAdditionalInformation('cc_cid_2');
        $ccType = $payment->getAdditionalInformation('cc_type_2');
        $ccOwner = $payment->getAdditionalInformation('cc_owner_2');
        $installments = $payment->getAdditionalInformation('installments_2') ?? 1;
        $saveCard = $payment->getAdditionalInformation('save_card_2') ?? 0;

        $fingerprint = $payment->getAdditionalInformation('fingerprint_2');

        return [
            'payment_method_id' => $this->helper->getMethodId('CREDIT_CARD'),
            'card_number' => preg_replace('/\D/', '', (string) $ccNumber),
            'card_holder' => $ccOwner,
            'card_expiration_date' => sprintf('%s%s', $ccExpMonth, $ccExpYear),
            'card_cvv' => $ccCid,
            'installments' => $installments,
            'card_brand' => $this->helper->getCardBrand($ccType),
            'save_card' => (bool) $saveCard,
            'fingerprint' => $fingerprint,
            'split' => 1
        ];
    }
}
