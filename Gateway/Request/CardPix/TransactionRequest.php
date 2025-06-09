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

        // Valores informados no checkout
        $amountCredit = (float)$payment->getAdditionalInformation('amount_credit');
        $amountPix = (float)$payment->getAdditionalInformation('amount_pix');
        $totalPaid = $amountCredit + $amountPix;

        // Valores originais do pedido
        $subtotal = (float)$order->getBaseSubtotal();
        $shipping = (float)$order->getShippingAmount();
        $discount = abs((float)$order->getDiscountAmount());

        // Converter para centavos
        $subtotalCents = (int)round($subtotal * 100);
        $shippingCents = (int)round($shipping * 100);
        $discountCents = (int)round($discount * 100);
        $creditCents = (int)round($amountCredit * 100);
        $pixCents = (int)round($amountPix * 100);
        $totalCents = $creditCents + $pixCents;

        // Proporção de cada meio
        $propCredit = $totalCents > 0 ? $creditCents / $totalCents : 0;
        $propPix = $totalCents > 0 ? $pixCents / $totalCents : 0;

        // Rateio de frete
        $shippingCredit = (int)floor($shippingCents * $propCredit);
        $shippingPix = (int)floor($shippingCents * $propPix);
        $shippingDiff = $shippingCents - ($shippingCredit + $shippingPix);
        if ($shippingDiff !== 0) {
            if ($creditCents >= $pixCents) {
                $shippingCredit += $shippingDiff;
            } else {
                $shippingPix += $shippingDiff;
            }
        }

        // Rateio de desconto
        $discountCredit = (int)floor($discountCents * $propCredit);
        $discountPix = (int)floor($discountCents * $propPix);
        $discountDiff = $discountCents - ($discountCredit + $discountPix);
        if ($discountDiff !== 0) {
            if ($creditCents >= $pixCents) {
                $discountCredit += $discountDiff;
            } else {
                $discountPix += $discountDiff;
            }
        }

        // Converter de volta para reais
        $shippingCreditReal = $shippingCredit / 100;
        $shippingPixReal = $shippingPix / 100;
        $discountCreditReal = $discountCredit / 100;
        $discountPixReal = $discountPix / 100;

        // Build as requisições separadas
        $cardRequest = $this->buildCardRequest($order, $payment, $amountCredit, $amountPix, $shippingCreditReal, $discountCreditReal);
        $pixRequest = $this->buildPixRequest($order, $payment, $amountCredit, $amountPix, $shippingPixReal, $discountPixReal);

        return [
            'card_request' => $cardRequest,
            'pix_request' => $pixRequest,
            'client_config' => ['store_id' => (int)$order->getStoreId()]
        ];
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
        // Get the base transaction request
        $transaction = $this->getTransaction($order, $amountCredit);

        // Apply discount for the PIX portion
        $transaction['transaction']['price_discount'] = (string)$discount;
        $transaction['transaction_shipping']['shipping_price'] = (string)$shipping;

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
        // Get the base transaction request
        $transaction = $this->getTransaction($order, $amountPix);

        // Apply discount for the Credit Card portion
        $transaction['transaction']['price_discount'] = (string)$discount;
        $transaction['transaction_shipping']['shipping_price'] = (string)$shipping;

        // Add PIX payment data
        $transaction['payment'] = [
            'payment_method_id' => $this->helper->getMethodId('PIX'),
            'split' => 1
        ];

        // Set specific PIX information in transaction
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
            throw new \Magento\Framework\Exception\LocalizedException(__('Customer is not logged in.'));
        }

        $creditCardResource = \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Vindi\VP\Model\ResourceModel\CreditCard\CollectionFactory::class)
            ->create()
            ->addFieldToFilter('entity_id', $paymentProfileId)
            ->addFieldToFilter('customer_id', $customerId)
            ->getFirstItem();

        if (!$creditCardResource->getId()) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Saved card not found or does not belong to the current customer.'));
        }

        $cvv = $payment->getAdditionalInformation('cc_cid');
        if (!$cvv) {
            throw new \Magento\Framework\Exception\LocalizedException(__('CVV is required for saved cards.'));
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
     * Retrieves new credit card data
     *
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return array
     */
    protected function getNewCardData($payment): array
    {
        $saveCard = $payment->getAdditionalInformation('save_card');
        $installments = $payment->getAdditionalInformation('installments') ?: 1;

        if ($saveCard) {
            $encryptedData = $this->encryptor->encrypt(json_encode([
                'cc_last_4' => substr($payment->getAdditionalInformation('cc_number_masked'), -4),
                'cc_exp_date' => $payment->getAdditionalInformation('cc_exp_month') . '/' . $payment->getAdditionalInformation('cc_exp_year'),
                'cc_name' => $payment->getAdditionalInformation('cc_owner')
            ]));
            $this->session->setData('encrypted_card_info', $encryptedData);
        }

        return [
            'payment_method_id'  => $this->helper->getMethodId($payment->getAdditionalInformation('cc_type')),
            'card_name'          => $payment->getAdditionalInformation('cc_owner'),
            'card_number'        => $payment->getAdditionalInformation('cc_number'),
            'card_expdate_month' => $payment->getAdditionalInformation('cc_exp_month'),
            'card_expdate_year'  => $payment->getAdditionalInformation('cc_exp_year'),
            'card_cvv'           => $payment->getAdditionalInformation('cc_cid'),
            'split'              => (string)$installments
        ];
    }
}
