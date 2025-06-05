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

namespace Vindi\VP\Gateway\Response\CardCard;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Vindi\VP\Helper\Data;
use Vindi\VP\Model\PaymentLinkService;

/**
 * Class TransactionHandler
 * Handles the response for Card + Card payment method
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
     * Handles response for Card + Card payment method
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

        // Process first Card response
        if (isset($response['card1_response'])) {
            $card1Response = $response['card1_response'];
            $payment->setAdditionalInformation('card1_payment_tid', $card1Response['tid'] ?? '');
            $payment->setAdditionalInformation('card1_status', $card1Response['status'] ?? '');
            $payment->setAdditionalInformation('card1_installments', $payment->getAdditionalInformation('installments'));
            $payment->setAdditionalInformation('card1_amount', $payment->getAdditionalInformation('amount_card1'));

            // Save new card if requested
            if (isset($card1Response['payment']['card_id']) &&
                $payment->getAdditionalInformation('save_card') &&
                $order->getCustomerId()) {
                $this->saveCardData($card1Response, $order->getCustomerId());
            }
        }

        // Process second Card response
        if (isset($response['card2_response'])) {
            $card2Response = $response['card2_response'];
            $payment->setAdditionalInformation('card2_payment_tid', $card2Response['tid'] ?? '');
            $payment->setAdditionalInformation('card2_status', $card2Response['status'] ?? '');
            $payment->setAdditionalInformation('card2_installments', $payment->getAdditionalInformation('installments_2'));
            $payment->setAdditionalInformation('card2_amount', $payment->getAdditionalInformation('amount_card2'));

            // Save second new card if requested
            if (isset($card2Response['payment']['card_id']) &&
                $payment->getAdditionalInformation('save_card_2') &&
                $order->getCustomerId()) {
                $this->saveCardData($card2Response, $order->getCustomerId());
            }
        }

        // Check if both card transactions were successful
        if (isset($response['card1_response']['status']) &&
            isset($response['card2_response']['status']) &&
            $response['card1_response']['status'] === 'approved' &&
            $response['card2_response']['status'] === 'approved'
        ) {
            $payment->setIsTransactionApproved(true);
            $payment->setIsTransactionPending(false);
        } else {
            $payment->setIsTransactionPending(true);
        }
    }

    /**
     * Save card data for future use
     *
     * @param array $response
     * @param int $customerId
     * @return void
     */
    private function saveCardData(array $response, int $customerId): void
    {
        if (!isset($response['payment']) ||
            !isset($response['payment']['card_id']) ||
            !isset($response['payment']['brand']) ||
            !isset($response['payment']['last_digits'])
        ) {
            return;
        }

        try {
            $this->helper->saveCustomerCard(
                $customerId,
                $response['payment']['card_id'],
                $response['payment']['brand'],
                $response['payment']['last_digits']
            );
        } catch (\Exception $e) {
            // Log error but don't interrupt the payment flow
            $this->helper->log('Error saving card: ' . $e->getMessage());
        }
    }
}
