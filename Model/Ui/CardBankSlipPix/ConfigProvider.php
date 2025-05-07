<?php
/**
 * Card + BolePix ConfigProvider for Vindi VP.
 *
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade this extension to a newer version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Model\Ui\CardBankSlipPix;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\App\RequestInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Vindi\VP\Helper\Data as HelperData;
use Vindi\VP\Model\Config\Source\CardImages as CardImagesSource;
use Vindi\VP\Model\ResourceModel\CreditCard\Collection as PaymentProfileCollection;

class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'vindi_vp_cardbankslippix';

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var Repository
     */
    protected $assetRepo;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var PaymentHelper
     */
    protected $paymentHelper;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @var PaymentProfileCollection
     */
    protected $paymentProfileCollection;

    /**
     * @var CardImagesSource
     */
    protected $creditCardTypeSource;

    /**
     * @param CheckoutSession          $checkoutSession
     * @param CustomerSession          $customerSession
     * @param Repository               $assetRepo
     * @param RequestInterface         $request
     * @param PaymentHelper            $paymentHelper
     * @param HelperData               $helper
     * @param PaymentProfileCollection $paymentProfileCollection
     * @param CardImagesSource         $creditCardTypeSource
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        Repository $assetRepo,
        RequestInterface $request,
        PaymentHelper $paymentHelper,
        HelperData $helper,
        PaymentProfileCollection $paymentProfileCollection,
        CardImagesSource $creditCardTypeSource
    ) {
        $this->checkoutSession          = $checkoutSession;
        $this->customerSession          = $customerSession;
        $this->assetRepo                = $assetRepo;
        $this->request                  = $request;
        $this->paymentHelper            = $paymentHelper;
        $this->helper                   = $helper;
        $this->paymentProfileCollection = $paymentProfileCollection;
        $this->creditCardTypeSource     = $creditCardTypeSource;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        $customerTaxvat = '';
        $customer       = $this->customerSession->getCustomer();
        if ($customer && $customer->getTaxvat()) {
            $raw = preg_replace('/\D/', '', (string)$customer->getTaxvat());
            $customerTaxvat = (strlen($raw) === 11) ? $raw : '';
        }

        return [
            'payment' => [
                self::CODE => [
                    'grand_total'               => $this->checkoutSession->getQuote()->getGrandTotal(),
                    'checkout_instructions'     => trim($this->helper->getConfig('checkout_instructions', self::CODE)),
                    'customer_taxvat'           => $customerTaxvat,
                    'sandbox'                   => (int)$this->helper->getGeneralConfig('use_sandbox'),
                    'isInstallmentsAllowedInStore' => (int)$this->helper->isInstallmentsAllowedInStore(),
                    'maxInstallments'           => (int)$this->helper->getMaxInstallments() ?: 1,
                    'minInstallmentsValue'      => (int)$this->helper->getMinInstallmentsValue(),
                    'saved_cards'               => $this->getPaymentProfiles(),
                    'credit_card_images'        => $this->getCreditCardImages(),
                    'bankslip_pix_enabled'      => true,
                    'enabledDocument'           => true
                ]
            ]
        ];
    }

    /**
     * Get saved payment profiles
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
     * Get credit card images
     *
     * @return array
     */
    protected function getCreditCardImages(): array
    {
        $images  = [];
        $options = $this->creditCardTypeSource->toOptionArray();
        foreach ($options as $option) {
            $images[] = [
                'code'  => $option['code'],
                'label' => $option['label'],
                'value' => $option['value']
            ];
        }
        return $images;
    }
}
