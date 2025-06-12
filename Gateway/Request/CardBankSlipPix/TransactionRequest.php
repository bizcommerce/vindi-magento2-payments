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
use function __;

/**
 * Class TransactionRequest
 * Handles the BankSlip + Pix transaction request building
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
     * Builds the CardBankSlipPix (Cartão + Boleto + Pix) request
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

        $amountCredit = (float)($payment->getAdditionalInformation('amount_credit') ?? 0);
        $amountBankSlip = (float)($payment->getAdditionalInformation('amount_bankslip') ?? 0);
        $amountPix = (float)($payment->getAdditionalInformation('amount_pix') ?? 0);

        // Fallback: se não vierem, dividir igualmente
        if ($amountCredit <= 0 && $amountBankSlip <= 0 && $amountPix <= 0) {
            $amountCredit = round($grandTotal / 3, 2);
            $amountBankSlip = round($grandTotal / 3, 2);
            $amountPix = $grandTotal - $amountCredit - $amountBankSlip;
        }

        // Proporção para shipping e desconto
        $totalSplit = $amountCredit + $amountBankSlip + $amountPix;
        $shippingCard = $totalSplit > 0 ? round($shipping * ($amountCredit / $totalSplit), 2) : 0;
        $shippingBankSlip = $totalSplit > 0 ? round($shipping * ($amountBankSlip / $totalSplit), 2) : 0;
        $shippingPix = $shipping - $shippingCard - $shippingBankSlip;
        $discountCard = $totalSplit > 0 ? round($discount * ($amountCredit / $totalSplit), 2) : 0;
        $discountBankSlip = $totalSplit > 0 ? round($discount * ($amountBankSlip / $totalSplit), 2) : 0;
        $discountPix = $discount - $discountCard - $discountBankSlip;

        // Montar requests separados
        $cardRequest = $this->buildCardRequest($order, $payment, $amountCredit, $shippingCard, $discountCard);
        $bankSlipRequest = $this->buildBankSlipRequest($order, $payment, $amountBankSlip, $shippingBankSlip, $discountBankSlip);
        $pixRequest = $this->buildPixRequest($order, $payment, $amountPix, $shippingPix, $discountPix);

        return [
            'request_card' => $cardRequest,
            'request_bankslip' => $bankSlipRequest,
            'request_pix' => $pixRequest,
            'client_config' => ['store_id' => (int)$order->getStoreId()]
        ];
    }

    private function buildCardRequest($order, $payment, float $amountCredit, float $shipping, float $discount): array
    {
        $transaction = $this->getTransaction($order, $amountCredit);
        $transaction['transaction']['price_discount'] = (string)$discount;
        $transaction['transaction_shipping']['shipping_price'] = (string)$shipping;
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
        $transaction['transaction_shipping']['shipping_price'] = (string)$shipping;
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
        $transaction['transaction_shipping']['shipping_price'] = (string)$shipping;
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
        $cvv = $payment->getAdditionalInformation('cc_cid');
        if (!$cvv) {
            throw new LocalizedException(__('CVV is required for saved cards.'));
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
            'card_cvv'           => $ccCid,
            'split'              => (string)($installments ?: 1)
        ];
        if ($fingerprint) {
            $cardData['fingerprint'] = $fingerprint;
        }
        return $cardData;
    }
}
