<?php
/**
 * Card + Card ConfigProvider for Vindi VP.
 *
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade this extension to a newer version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Model\Ui\CardCard;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\CcConfig;
use Magento\Payment\Model\CcGenericConfigProvider;
use Vindi\VP\Helper\Data as HelperData;
use Vindi\VP\Model\ResourceModel\PaymentProfile\Collection as PaymentProfileCollection;
use Vindi\VP\Model\Config\Source\CardImages as CardImagesSource;

class ConfigProvider extends CcGenericConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'vindi_vp_cardcard';

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var HelperData
     */
    protected $helperData;

    /**
     * @var PaymentProfileCollection
     */
    protected $paymentProfileCollection;

    /**
     * @var CardImagesSource
     */
    protected $creditCardTypeSource;

    /**
     * @param CcConfig                   $ccConfig
     * @param PaymentHelper              $paymentHelper
     * @param HelperData                 $helperData
     * @param CustomerSession            $customerSession
     * @param CheckoutSession            $checkoutSession
     * @param PaymentProfileCollection   $paymentProfileCollection
     * @param CardImagesSource           $creditCardTypeSource
     */
    public function __construct(
        CcConfig $ccConfig,
        PaymentHelper $paymentHelper,
        HelperData $helperData,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        PaymentProfileCollection $paymentProfileCollection,
        CardImagesSource $creditCardTypeSource
    ) {
        parent::__construct($ccConfig, $paymentHelper, [self::CODE]);
        $this->helperData               = $helperData;
        $this->customerSession          = $customerSession;
        $this->checkoutSession          = $checkoutSession;
        $this->paymentProfileCollection = $paymentProfileCollection;
        $this->creditCardTypeSource     = $creditCardTypeSource;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): array
    {
        $customerTaxvat = '';
        $customer       = $this->customerSession->getCustomer();
        if ($customer && $customer->getTaxvat()) {
            $raw = preg_replace('/\D/', '', (string)$customer->getTaxvat());
            $customerTaxvat = (strlen($raw) === 11) ? $raw : '';
        }

        $grandTotal = $this->checkoutSession->getQuote()->getGrandTotal();

        return [
            'payment' => [
                self::CODE => [
                    'availableTypes'             => $this->getCcAvailableTypes(),
                    'months'                     => [self::CODE => $this->getCcMonths()],
                    'years'                      => [self::CODE => $this->getCcYears()],
                    'hasVerification'            => [self::CODE => $this->hasVerification(self::CODE)],
                    'isInstallmentsAllowedInStore' => (int)$this->helperData->isInstallmentsAllowedInStore(),
                    'maxInstallments'            => (int)$this->helperData->getMaxInstallments() ?: 1,
                    'minInstallmentsValue'       => (int)$this->helperData->getMinInstallmentsValue(),
                    'saved_cards'                => $this->getPaymentProfiles(),
                    'credit_card_images'         => $this->getCreditCardImages(),
                    'double_card_enabled'        => true,
                    'enabledDocument'            => true,
                    'customer_taxvat'            => $customerTaxvat,
                    'grand_total'                => $grandTotal
                ]
            ]
        ];
    }

    /**
     * Get saved payment profiles.
     *
     * @return array
     */
    protected function getPaymentProfiles(): array
    {
        $profiles = [];
        if ($this->customerSession->isLoggedIn()) {
            $customerId = $this->customerSession->getCustomerId();
            $this->paymentProfileCollection
                ->addFieldToFilter('customer_id', $customerId)
                ->addFieldToFilter('cc_type', ['neq' => '']);
            foreach ($this->paymentProfileCollection as $profile) {
                $profiles[] = [
                    'id'          => $profile->getId(),
                    'card_number' => (string)$profile->getCcLast4(),
                    'card_type'   => (string)$profile->getCcType()
                ];
            }
        }
        return $profiles;
    }

    /**
     * Get credit card images.
     *
     * @return array
     */
    protected function getCreditCardImages(): array
    {
        $images  = [];
        $options = $this->creditCardTypeSource->toOptionArray();
        foreach ($options as $opt) {
            $images[] = [
                'code'  => $opt['code'],
                'label' => $opt['label'],
                'value' => $opt['value']
            ];
        }
        return $images;
    }
}
