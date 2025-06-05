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

        // Get split amounts from payment additional information
        $amountBankSlip = (float)$payment->getAdditionalInformation('amount_bankslip');
        $amountPix = (float)$payment->getAdditionalInformation('amount_pix');

        // Build the transaction request for bank slip
        $bankSlipRequest = $this->buildBankSlipRequest($order, $payment, $amountBankSlip, $amountPix);

        // Build the transaction request for PIX
        $pixRequest = $this->buildPixRequest($order, $payment, $amountBankSlip, $amountPix);

        return [
            'bankslip_request' => $bankSlipRequest,
            'pix_request' => $pixRequest,
            'client_config' => ['store_id' => (int)$order->getStoreId()]
        ];
    }

    /**
     * Build the bank slip portion of the request
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountBankSlip
     * @param float $amountPix
     * @return array
     */
    private function buildBankSlipRequest($order, $payment, float $amountBankSlip, float $amountPix): array
    {
        // Get the base transaction request
        $transaction = $this->getTransaction($order, $amountBankSlip);

        // Apply discount for the PIX portion
        $transaction['transaction']['price_discount'] = (string)$amountPix;

        // Add bank slip payment data
        $transaction['payment'] = [
            'payment_method_id' => $this->helper->getMethodId('BANK_SLIP'),
            'split' => 1
        ];

        // Set specific bank slip information in transaction
        $transaction['transaction']['order_number'] = $order->getIncrementId() . '-BANKSLIP';

        return $transaction;
    }

    /**
     * Build the PIX portion of the request
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param float $amountBankSlip
     * @param float $amountPix
     * @return array
     */
    private function buildPixRequest($order, $payment, float $amountBankSlip, float $amountPix): array
    {
        // Get the base transaction request
        $transaction = $this->getTransaction($order, $amountPix);

        // Apply discount for the bank slip portion
        $transaction['transaction']['price_discount'] = (string)$amountBankSlip;

        // Add PIX payment data
        $transaction['payment'] = [
            'payment_method_id' => $this->helper->getMethodId('PIX'),
            'split' => 1
        ];

        // Set specific PIX information in transaction
        $transaction['transaction']['order_number'] = $order->getIncrementId() . '-PIX';

        return $transaction;
    }
}
