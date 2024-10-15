<?php

declare(strict_types=1);

namespace Vindi\VP\Block\Customer;

use Magento\Customer\Model\Session as CustomerSession;
use Vindi\VP\Model\PaymentLinkService;
use Magento\Framework\View\Element\Template;
use Magento\Framework\App\Request\Http as HttpRequest;

class PaymentLinkNotification extends Template
{
    /**
     * @var PaymentLinkService
     */
    private $paymentLinkService;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var HttpRequest
     */
    private $request;

    /**
     * @param Template\Context $context
     * @param PaymentLinkService $paymentLinkService
     * @param CustomerSession $customerSession
     * @param HttpRequest $request
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        PaymentLinkService $paymentLinkService,
        CustomerSession $customerSession,
        HttpRequest $request,
        array $data = []
    ) {
        $this->paymentLinkService = $paymentLinkService;
        $this->customerSession = $customerSession;
        $this->request = $request;
        parent::__construct($context, $data);
    }

    /**
     * Check if there is a pending payment link for the logged-in customer.
     *
     * @return string|false
     */
    public function getPaymentLink()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return false;
        }

        $fullActionName = $this->request->getFullActionName();

        if ($fullActionName !== 'customer_account_index') {
            return false;
        }

        $customerId = $this->customerSession->getCustomerId();
        $paymentLink = $this->paymentLinkService->getMostRecentPendingPaymentLinkByCustomerId($customerId);

        if ($paymentLink && $paymentLink->getStatus() === 'pending') {
            return true;
        }

        return false;
    }
}
