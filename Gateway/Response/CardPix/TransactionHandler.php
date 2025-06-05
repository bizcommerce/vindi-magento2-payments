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

namespace Vindi\VP\Gateway\Response\CardPix;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Vindi\VP\Helper\Data;
use Vindi\VP\Model\PaymentLinkService;
use Vindi\VP\Model\AccessToken;

/**
 * Class TransactionHandler
 * Handles the response for Card + Pix payment method
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
     * Handles response for Card + Pix payment method
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

        // Process Card response
        if (isset($response['card_response'])) {
            $cardResponse = $response['card_response'];
            $payment->setAdditionalInformation('card_payment_tid', $cardResponse['tid'] ?? '');
            $payment->setAdditionalInformation('card_status', $cardResponse['status'] ?? '');
            $payment->setAdditionalInformation('card_installments', $payment->getAdditionalInformation('installments'));
            $payment->setAdditionalInformation('card_amount', $payment->getAdditionalInformation('amount_credit'));
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

            // Create payment link for PIX portion
            if ($pixHash && $pixUrl) {
                $this->createPixPaymentLink($payment, $pixUrl, $pixHash, $pixExpiration);
            }
        }

        // Set the transaction ID as a combination of both transaction IDs
        $cardTid = $payment->getAdditionalInformation('card_payment_tid') ?? '';
        $pixTid = $payment->getAdditionalInformation('pix_payment_tid') ?? '';
        $combinedTid = $cardTid . '-' . $pixTid;

        $payment->setTransactionId($combinedTid);
        $payment->setIsTransactionClosed(false);
    }

    /**
     * Create a payment link for the PIX portion of the payment
     *
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param string $pixUrl
     * @param string $pixHash
     * @param string $pixExpiration
     * @return void
     */
    private function createPixPaymentLink($payment, $pixUrl, $pixHash, $pixExpiration)
    {
        $order = $payment->getOrder();
        $expirationTime = $pixExpiration ? strtotime($pixExpiration) : (time() + 3600); // Default 1 hour

        $this->paymentLinkService->createPaymentLink(
            $order,
            $pixUrl,
            $pixHash,
            $expirationTime,
            $payment->getAdditionalInformation('amount_pix')
        );
    }
}
