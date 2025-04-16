<?php
/**
 * Card + Bolepix ConfigProvider for Vindi VP.
 *
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade this extension to a newer version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Model\Ui\CardBankSlipPix;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\App\RequestInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Vindi\VP\Helper\Data;

class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'vindi_vp_cardbankslippix';

    /**
     * @var Session
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
     * @var Data
     */
    protected $helper;

    /**
     * @param Session $checkoutSession
     * @param CustomerSession $customerSession
     * @param Repository $assetRepo
     * @param RequestInterface $request
     * @param PaymentHelper $paymentHelper
     * @param Data $helper
     */
    public function __construct(
        Session $checkoutSession,
        CustomerSession $customerSession,
        Repository $assetRepo,
        RequestInterface $request,
        PaymentHelper $paymentHelper,
        Data $helper
    ) {
        $this->checkoutSession  = $checkoutSession;
        $this->customerSession  = $customerSession;
        $this->assetRepo        = $assetRepo;
        $this->request          = $request;
        $this->paymentHelper    = $paymentHelper;
        $this->helper           = $helper;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $customerTaxvat = '';
        $customer = $this->customerSession->getCustomer();
        if ($customer && $customer->getTaxvat()) {
            $taxVat = preg_replace('/[^0-9]/', '', (string)$customer->getTaxvat());
            $customerTaxvat = strlen($taxVat) == 11 ? $taxVat : '';
        }

        return [
            'payment' => [
                self::CODE => [
                    'grand_total'          => $this->checkoutSession->getQuote()->getGrandTotal(),
                    'checkout_instructions'=> trim($this->helper->getConfig('checkout_instructions', self::CODE)),
                    'customer_taxvat'      => $customerTaxvat,
                    'sandbox'              => (int)$this->helper->getGeneralConfig('use_sandbox')
                ]
            ]
        ];
    }
}
