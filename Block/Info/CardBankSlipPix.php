<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Block\Info;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Model\Config;

class CardBankSlipPix extends AbstractInfo
{
    protected $_template = 'Vindi_VP::payment/info/cardbankslippix.phtml';

    /**
     * @var ConfigInterface
     */
    protected $config;

    /**
     * @var Config
     */
    protected $paymentConfig;

    /**
     * @var DateTime
     */
    protected $date;

    /**
     * @var PriceCurrencyInterface
     */
    protected $priceCurrency;

    /**
     * CardBankSlipPix constructor.
     * @param Context $context
     * @param ConfigInterface $config
     * @param Config $paymentConfig
     * @param PriceCurrencyInterface $priceCurrency
     * @param DateTime $date
     * @param array $data
     */
    public function __construct(
        Context $context,
        ConfigInterface $config,
        Config $paymentConfig,
        PriceCurrencyInterface $priceCurrency,
        DateTime $date,
        array $data = []
    ) {
        parent::__construct($context, $config, $paymentConfig, $data);
        $this->paymentConfig = $paymentConfig;
        $this->date = $date;
        $this->priceCurrency = $priceCurrency;
    }

    /**
     * @inheritDoc
     */
    public function _construct()
    {
        $this->setTemplate($this->_template);
    }

    /**
     * Get card payment amount
     *
     * @return string
     * @throws LocalizedException
     */
    public function getCardAmount(): string
    {
        $payment = $this->getInfo();
        $amount = $payment->getAdditionalInformation('card_amount') ?? 0;
        return $this->priceCurrency->format($amount, false);
    }

    /**
     * Get card payment TID
     *
     * @return string
     * @throws LocalizedException
     */
    public function getCardTid(): string
    {
        $payment = $this->getInfo();
        return (string) $payment->getAdditionalInformation('card_payment_tid');
    }

    /**
     * Get card payment status
     *
     * @return string
     * @throws LocalizedException
     */
    public function getCardStatus(): string
    {
        $payment = $this->getInfo();
        $status = $payment->getAdditionalInformation('card_status');

        $statusMap = [
            '1' => __('Pending'),
            '2' => __('Processing'),
            '3' => __('Authorized'),
            '4' => __('Captured'),
            '5' => __('Cancelled'),
            '6' => __('Refunded'),
            '7' => __('Failed')
        ];

        return (string) ($statusMap[$status] ?? __('Unknown'));
    }

    /**
     * Get card installments
     *
     * @return string
     * @throws LocalizedException
     */
    public function getCardInstallments(): string
    {
        $payment = $this->getInfo();
        $installments = $payment->getAdditionalInformation('card_installments') ?? 1;
        return (string) $installments;
    }

    /**
     * Get Bolepix amount
     *
     * @return string
     * @throws LocalizedException
     */
    public function getBolepixAmount(): string
    {
        $payment = $this->getInfo();
        $amount = $payment->getAdditionalInformation('amount_bolepix') ?? 0;
        return $this->priceCurrency->format($amount, false);
    }

    /**
     * Get overall payment status
     *
     * @return string
     * @throws LocalizedException
     */
    public function getPaymentStatus(): string
    {
        $payment = $this->getInfo();
        $status = $payment->getAdditionalInformation('payment_status');

        $statusMap = [
            'card_approved_bolepix_pending' => __('Card Approved - Bolepix Pending'),
            'card_failed_bolepix_cancelled' => __('Card Failed - Bolepix Cancelled'),
            'both_approved' => __('Both Payments Approved'),
            'bolepix_failed' => __('Card Approved - Bolepix Failed')
        ];

        return (string) ($statusMap[$status] ?? __('Processing'));
    }

    /**
     * Check if card payment was successful
     *
     * @return bool
     * @throws LocalizedException
     */
    public function isCardSuccessful(): bool
    {
        $payment = $this->getInfo();
        $cardStatus = $payment->getAdditionalInformation('card_status');
        return in_array($cardStatus, ['3', '4']);
    }

    /**
     * Check if payment is in mixed status (card approved, bolepix pending)
     *
     * @return bool
     * @throws LocalizedException
     */
    public function isMixedStatus(): bool
    {
        $payment = $this->getInfo();
        $status = $payment->getAdditionalInformation('payment_status');
        return $status === 'card_approved_bolepix_pending';
    }

    /**
     * Get BankSlip URL if available
     *
     * @return string
     * @throws LocalizedException
     */
    public function getBankSlipUrl(): string
    {
        $payment = $this->getInfo();
        return (string) $payment->getAdditionalInformation('bank_slip_url');
    }

    /**
     * Get PIX QR Code EMV if available
     *
     * @return string
     * @throws LocalizedException
     */
    public function getPixEmv(): string
    {
        $payment = $this->getInfo();
        return (string) $payment->getAdditionalInformation('qr_code_emv');
    }

    /**
     * Get PIX QR Code image URL if available
     *
     * @return string
     * @throws LocalizedException
     */
    public function getPixQRCodeImage(): string
    {
        $payment = $this->getInfo();
        return (string) $payment->getAdditionalInformation('qr_code_url');
    }
}
