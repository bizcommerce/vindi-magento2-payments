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

namespace Vindi\VP\Gateway\Response\CardBankSlipPix;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Vindi\VP\Helper\Data;
use Vindi\VP\Model\PaymentLinkService;

/**
 * Class TransactionHandler
 * Handles the response for BankSlip + Pix payment method
 */
class TransactionHandler implements HandlerInterface
{
    /**
     * @var Json
     */
    protected $serializer;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var PaymentLinkService
     */
    protected $paymentLinkService;

    /**
     * @param Json $serializer
     * @param Data $helper
     * @param PaymentLinkService $paymentLinkService
     */
    public function __construct(
        Json $serializer,
        Data $helper,
        PaymentLinkService $paymentLinkService
    ) {
        $this->serializer = $serializer;
        $this->helper = $helper;
        $this->paymentLinkService = $paymentLinkService;
    }

    /**
     * Handles response for BankSlip + Pix payment method
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        if (!isset($handlingSubject['payment']) || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        $paymentDO = $handlingSubject['payment'];
        $payment = $paymentDO->getPayment();
        $order = $payment->getOrder();

        // Store the complete response data as additional information
        $payment->setAdditionalInformation('vindi_response', $this->serializer->serialize($response));

        // Process BankSlip response
        if (isset($response['bankslip_response'])) {
            $bankslipResponse = $response['bankslip_response'];
            $payment->setAdditionalInformation('bankslip_payment_tid', $bankslipResponse['tid'] ?? '');
            $payment->setAdditionalInformation('bankslip_status', $bankslipResponse['status'] ?? '');
            $payment->setAdditionalInformation('bankslip_url', $bankslipResponse['bank_slip']['url'] ?? '');
            $payment->setAdditionalInformation('bankslip_digitable_line', $bankslipResponse['bank_slip']['digitable_line'] ?? '');
            $payment->setAdditionalInformation('bankslip_due_date', $bankslipResponse['bank_slip']['due_date'] ?? '');
            $payment->setAdditionalInformation('bankslip_amount', $payment->getAdditionalInformation('amount_bankslip'));
        }

        // Process Pix response
        if (isset($response['pix_response'])) {
            $pixResponse = $response['pix_response'];
            $pixTid = $pixResponse['tid'] ?? '';
            $pixHash = $pixResponse['pix_code'] ?? '';
            $pixUrl = $pixResponse['pix_url'] ?? '';
            $pixExpiration = $pixResponse['pix_expiration_date'] ?? '';

            $payment->setAdditionalInformation('pix_payment_tid', $pixTid);
            $payment->setAdditionalInformation('pix_hash', $pixHash);
            $payment->setAdditionalInformation('pix_url', $pixUrl);
            $payment->setAdditionalInformation('pix_expiration', $pixExpiration);
            $payment->setAdditionalInformation('pix_amount', $payment->getAdditionalInformation('amount_pix'));

            if ($pixTid && $pixHash && $this->helper->getGeneralConfig('save_payment_links')) {
                $expirationDate = '';
                if ($pixExpiration) {
                    $expirationDate = date('Y-m-d H:i:s', strtotime($pixExpiration));
                }

                // Save PIX payment link
                $this->paymentLinkService->create(
                    $pixTid,
                    $order->getIncrementId(),
                    $payment->getAdditionalInformation('amount_pix'),
                    $pixUrl,
                    $expirationDate,
                    'pix',
                    $pixHash,
                    $order->getCustomerId(),
                    $order->getStoreId()
                );
            }
        }

        if (isset($response['bankslip_response']['status']) &&
            isset($response['pix_response']['status']) &&
            $response['bankslip_response']['status'] === 'approved' &&
            $response['pix_response']['status'] === 'approved'
        ) {
            $payment->setIsTransactionApproved(true);
            $payment->setIsTransactionPending(false);
        } else {
            $payment->setIsTransactionPending(true);
        }
    }
}
