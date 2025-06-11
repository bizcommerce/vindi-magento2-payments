<?php
namespace Vindi\VP\Block\PaymentProfile;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Vindi\VP\Model\Config\Source\CardImages as CardImagesSource;
use Vindi\VP\Model\ResourceModel\CreditCard\Collection as CreditCardCollection;

/**
 * Class Remove
 * @package Vindi\VP\Block\PaymentProfile
 */
class Remove extends Template
{
    /**
     * @var CreditCardCollection
     */
    protected $paymentProfileCollection;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var CardImagesSource
     */
    protected $creditCardTypeSource;

    /**
     * Remove constructor.
     * @param Context $context
     * @param CreditCardCollection $paymentProfileCollection
     * @param CustomerSession $customerSession
     * @param CardImagesSource $creditCardTypeSource
     * @param array $data
     */
    public function __construct(
        Context $context,
        CreditCardCollection $paymentProfileCollection,
        CustomerSession $customerSession,
        CardImagesSource $creditCardTypeSource,
        array $data = []
    ) {
        $this->paymentProfileCollection = $paymentProfileCollection;
        $this->customerSession = $customerSession;
        $this->creditCardTypeSource = $creditCardTypeSource;
        parent::__construct($context, $data);
    }

    /**
     * Get credit card image URL by credit card type.
     *
     * @param string $ccType
     * @return string|null
     */
    public function getCreditCardImage($ccType)
    {
        $creditCardOptionArray = $this->creditCardTypeSource->toOptionArray();

        foreach ($creditCardOptionArray as $creditCardOption) {
            if ($creditCardOption['label']->getText() === $ccType) {
                return $creditCardOption['value'];
            }
        }
        return null;
    }

    /**
     * Retrieve current payment profile based on ID in URL.
     *
     * @return \Vindi\VP\Model\PaymentProfile|null
     */
    public function getPaymentProfile()
    {
        $profileId = $this->getRequest()->getParam('id');
        if ($profileId) {
            return $this->paymentProfileCollection->getItemById($profileId);
        }
        return null;
    }
}
