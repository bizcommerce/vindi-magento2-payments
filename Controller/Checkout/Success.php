<?php

declare(strict_types=1);

/**
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 *
 *
 */

namespace Vindi\VP\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Vindi\VP\Helper\Data;
use Vindi\VP\Model\PaymentLinkService;

class Success implements HttpGetActionInterface
{
    /**
     * @var PageFactory
     */
    protected PageFactory $resultPageFactory;

    /**
     * @var PaymentLinkService
     */
    private PaymentLinkService $paymentLinkService;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var RedirectFactory
     */
    private RedirectFactory $redirectFactory;

    /**
     * @var Data
     */
    private Data $helperData;

    /**
     * @var ManagerInterface
     */
    private ManagerInterface $messageManager;

    /**
     * @param PageFactory $resultPageFactory
     * @param PaymentLinkService $paymentLinkService
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param Data $helperData
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        PageFactory $resultPageFactory,
        PaymentLinkService $paymentLinkService,
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        Data $helperData,
        ManagerInterface $messageManager
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->paymentLinkService = $paymentLinkService;
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->helperData = $helperData;
        $this->messageManager = $messageManager;
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Redirect|\Magento\Framework\Controller\ResultInterface|\Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        $result = $this->resultPageFactory->create();
        $orderId = $this->request->getParam('order_id');
        $order = $this->paymentLinkService->getOrderByOrderId($orderId);

        if (!$order) {
            $this->messageManager->addWarningMessage(__('Order does not exist.'));
            return $this->redirectFactory->create()->setPath('/');
        }

        $orderStatus = $order->getStatus();
        $configStatus = $this->helperData->getConfig('order_status', $order->getPayment()->getMethod());
        $isCcMethod = str_contains($order->getPayment()->getMethod(), 'cc');

        if ($order->hasInvoices()) {
            $this->messageManager->addWarningMessage(__('Order already has an invoice.'));
            return $this->redirectFactory->create()->setPath('/');
        }

        $paymentLink = $this->paymentLinkService->getPaymentLink($orderId);
        $paymentLinkStatus = $paymentLink->getStatus();

        if ($paymentLinkStatus === 'expired') {
            $this->messageManager->addWarningMessage(__('This payment link has expired.'));
            return $this->redirectFactory->create()->setPath('/');
        }

        try {
            if (!$orderId || (!$isCcMethod && $orderStatus !== $configStatus)) {
                return $this->redirectFactory->create()->setPath('/');
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('An error occurred while processing your request. Please try again later.')
            );

            return $this->redirectFactory->create()->setPath('/');
        }

        return $result;
    }
}
