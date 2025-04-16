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

use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Vindi\VP\Helper\Data;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\CcConfig;
use Magento\Payment\Model\CcGenericConfigProvider;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Source;

class ConfigProvider extends CcGenericConfigProvider
{
    public const CODE = 'vindi_vp_cardcard';

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var Source
     */
    protected $assetSource;

    /**
     * @param Session $checkoutSession
     * @param CustomerSession $customerSession
     * @param Data $helper
     * @param CcConfig $ccConfig
     * @param UrlInterface $urlBuilder
     * @param PaymentHelper $paymentHelper
     * @param Source $assetSource
     */
    public function __construct(
        Session $checkoutSession,
        CustomerSession $customerSession,
        Data $helper,
        CcConfig $ccConfig,
        UrlInterface $urlBuilder,
        PaymentHelper $paymentHelper,
        Source $assetSource
    ) {
        parent::__construct($ccConfig, $paymentHelper, [self::CODE]);
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->helper = $helper;
        $this->urlBuilder = $urlBuilder;
        $this->assetSource = $assetSource;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $grandTotal = $this->checkoutSession->getQuote()->getGrandTotal();
        $customerTaxvat = '';
        $customer = $this->customerSession->getCustomer();
        if ($customer && $customer->getTaxvat()) {
            $taxVat = preg_replace('/[^0-9]/', '', (string)$customer->getTaxvat());
            $customerTaxvat = strlen($taxVat) == 11 ? $taxVat : '';
        }
        return [
            'payment' => [
                self::CODE => [
                    'grand_total'      => $grandTotal,
                    'customer_taxvat'  => $customerTaxvat,
                    'sandbox'          => (int)$this->helper->getGeneralConfig('use_sandbox'),
                    'availableTypes'   => $this->getCcAvailableTypes(self::CODE)
                ],
                'ccform' => [
                    'grandTotal'      => [self::CODE => $grandTotal],
                    'months'          => [self::CODE => $this->getCcMonths()],
                    'years'           => [self::CODE => $this->getCcYears()],
                    'hasVerification' => [self::CODE => $this->hasVerification(self::CODE)],
                    'cvvImageUrl'     => [self::CODE => $this->getCvvImageUrl()],
                    'urls'            => [
                        self::CODE => [
                            'retrieve_installments' => $this->urlBuilder->getUrl('vindi_vp/installments/retrieve')
                        ]
                    ]
                ]
            ]
        ];
    }
}
