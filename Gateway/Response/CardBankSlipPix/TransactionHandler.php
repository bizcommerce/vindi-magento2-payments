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
use Vindi\VP\Model\MultiPaymentQueue;

/**
 * Class TransactionHandler
 * Handles the response for Card + BankSlip + Pix payment method
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
     * @var MultiPaymentQueueService
     */
    protected $multiPaymentQueueService;

    /**
     * @param Json $serializer
     * @param Data $helper
     * @param PaymentLinkService $paymentLinkService
     * @param MultiPaymentQueueService $multiPaymentQueueService
     */
    public function __construct(
        Json $serializer,
        Data $helper,
        PaymentLinkService $paymentLinkService,
        \Vindi\VP\Model\MultiPaymentQueueService $multiPaymentQueueService
    ) {
        $this->serializer = $serializer;
        $this->helper = $helper;
        $this->paymentLinkService = $paymentLinkService;
        $this->multiPaymentQueueService = $multiPaymentQueueService;
    }

    /**
     * Handles response for Card + BankSlip + Pix payment method (only processes card, queues BankSlip and PIX)
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

        // Log para debug
        $this->helper->log(
            "CardBankSlipPix TransactionHandler - Processing order {$order->getIncrementId()}",
            'cardbankslippix_handler'
        );

        // Process Card response (primary transaction)
        if (isset($response['transaction'])) {
            $cardTransaction = $response['transaction'];
            $cardTid = $cardTransaction['payment']['tid'] ?? '';
            $cardStatus = $cardTransaction['status_id'] ?? '';

            // Use transaction_id as fallback when tid is empty (for declined transactions)
            if (empty($cardTid) && isset($cardTransaction['transaction_id'])) {
                $cardTid = (string)$cardTransaction['transaction_id'];

                $this->helper->log(
                    "CardBankSlipPix - Using transaction_id as TID fallback for order {$order->getIncrementId()}: {$cardTid}",
                    'cardbankslippix_handler'
                );
            }

            // Store card payment information
            $payment->setAdditionalInformation('card_payment_tid', $cardTid);
            $payment->setAdditionalInformation('card_status', $cardStatus);
            $payment->setAdditionalInformation('card_installments', $payment->getAdditionalInformation('installments'));
            $payment->setAdditionalInformation('card_amount', $payment->getAdditionalInformation('amount_credit'));

            // Set the transaction ID for the card portion - ensure it's not empty
            if (!empty($cardTid)) {
                $payment->setTransactionId($cardTid);
            } else {
                // Last resort: use order increment ID with timestamp
                $fallbackTid = $order->getIncrementId() . '-' . time();
                $payment->setTransactionId($fallbackTid);

                $this->helper->log(
                    "CardBankSlipPix - Using fallback TID for order {$order->getIncrementId()}: {$fallbackTid}",
                    'cardbankslippix_handler'
                );
            }

            $payment->setIsTransactionClosed(false);

            // Check if card payment was successful
            if ($this->isSuccessfulResponse($cardTransaction)) {
                // Card payment success - update existing Bolepix queue record with card TID and keep pending status
                $this->updateQueueRecords($order, $cardTid, MultiPaymentQueue::STATUS_PENDING);

                // Set payment status
                $payment->setAdditionalInformation('payment_status', 'card_approved_bolepix_pending');

                $this->helper->log(
                    "CardBankSlipPix - Card payment successful for order {$order->getIncrementId()}, TID: {$cardTid}. Bolepix remains pending for processing.",
                    'cardbankslippix_handler'
                );
            } else {
                // Card payment failed - update existing Bolepix queue record to failed status (cancelled due to card failure)
                $this->updateQueueRecords($order, $cardTid, MultiPaymentQueue::STATUS_FAILED);

                // Set payment status
                $payment->setAdditionalInformation('payment_status', 'card_failed_bolepix_cancelled');

                $this->helper->log(
                    "CardBankSlipPix - Card payment failed for order {$order->getIncrementId()}, Status: {$cardStatus}. Bolepix cancelled due to card failure.",
                    'cardbankslippix_handler'
                );

                // Mark payment as failed but don't throw exception to allow order processing
                $payment->setIsTransactionPending(false);
                $payment->setIsTransactionClosed(true);
            }
        }

        // Store the complete response data as additional information
        $payment->setAdditionalInformation('vindi_response', $this->serializer->serialize($response));
    }

    /**
     * Update existing Bolepix queue record with card transaction ID and status
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $cardTid
     * @param string $status
     * @return void
     */
    private function updateQueueRecords($order, string $cardTid, string $status): void
    {
        try {
            // Find the existing Bolepix queue record for this order
            $queueItems = $this->multiPaymentQueueService->getByOrderId((int)$order->getId());

            $bolepixQueueFound = false;

            foreach ($queueItems as $queueItem) {
                $errorMessage = null;
                if ($status === MultiPaymentQueue::STATUS_FAILED) {
                    $errorMessage = 'Payment cancelled due to card payment failure';
                }

                if ($queueItem->getSecondaryMethodType() === MultiPaymentQueue::SECONDARY_METHOD_BOLEPIX) {
                    $bolepixQueueFound = true;

                    // Update the queue record with card TID and status
                    $queueItem->setPrimaryTransactionId($cardTid);

                    // Use the updateStatus method to save
                    $this->multiPaymentQueueService->updateStatus($queueItem, $status, [], $errorMessage);

                    $this->helper->log(
                        "CardBankSlipPix - Updated Bolepix queue record for order {$order->getIncrementId()} with card TID: {$cardTid}, status: {$status}",
                        'cardbankslippix_queue'
                    );
                    break; // Only one Bolepix record per order
                }
            }

            if (!$bolepixQueueFound) {
                $this->helper->log(
                    "CardBankSlipPix - WARNING: No Bolepix queue record found for order {$order->getIncrementId()} to update with card TID: {$cardTid}",
                    'cardbankslippix_error'
                );
            }

        } catch (\Exception $e) {
            $this->helper->log(
                "CardBankSlipPix - Error updating queue records for order {$order->getIncrementId()}: {$e->getMessage()}",
                'cardbankslippix_error'
            );
        }
    }

    /**
     * Check if the response was successful
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulResponse(array $response): bool
    {
        // Check if we have a successful card transaction
        $statusId = $response['status_id'] ?? null;
        $tid = $response['payment']['tid'] ?? null;

        // Status 3 = Authorized, Status 4 = Captured - both are successful for cards
        return !empty($tid) && in_array($statusId, ['3', '4']);
    }
}
