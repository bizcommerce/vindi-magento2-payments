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
use Vindi\VP\Model\MultiPaymentQueue;

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
     * Handles response for Card + Pix payment method (only processes card, queues PIX)
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
            "CardPix TransactionHandler - Processing order {$order->getIncrementId()}",
            'cardpix_handler'
        );

        // Process Card response (primary transaction)
        if (isset($response['transaction'])) {
            $cardTransaction = $response['transaction'];
            $cardTid = $cardTransaction['payment']['tid'] ?? '';
            $cardStatus = $cardTransaction['status_id'] ?? '';

            // Store card payment information
            $payment->setAdditionalInformation('card_payment_tid', $cardTid);
            $payment->setAdditionalInformation('card_status', $cardStatus);
            $payment->setAdditionalInformation('card_installments', $payment->getAdditionalInformation('installments'));
            $payment->setAdditionalInformation('card_amount', $payment->getAdditionalInformation('amount_credit'));

            // Set the transaction ID for the card portion
            $payment->setTransactionId($cardTid);
            $payment->setIsTransactionClosed(false);

            // Check if card payment was successful
            if ($this->isSuccessfulResponse($cardTransaction)) {
                // Card payment success - update existing PIX queue record with card TID and keep pending status
                $this->updatePixQueueRecord($order, $cardTid, MultiPaymentQueue::STATUS_PENDING);
                
                // Set payment status
                $payment->setAdditionalInformation('payment_status', 'card_approved_pix_pending');
                
                $this->helper->log(
                    "CardPix - Card payment successful for order {$order->getIncrementId()}, TID: {$cardTid}. PIX remains pending for processing.",
                    'cardpix_handler'
                );
            } else {
                // Card payment failed - update existing PIX queue record to failed status (cancelled due to card failure)
                $this->updatePixQueueRecord($order, $cardTid, MultiPaymentQueue::STATUS_FAILED);
                
                // Set payment status
                $payment->setAdditionalInformation('payment_status', 'card_failed_pix_cancelled');
                
                $this->helper->log(
                    "CardPix - Card payment failed for order {$order->getIncrementId()}, Status: {$cardStatus}. PIX cancelled due to card failure.",
                    'cardpix_handler'
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
     * Update existing PIX queue record with card transaction ID and status
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $cardTid
     * @param string $status
     * @return void
     */
    private function updatePixQueueRecord($order, string $cardTid, string $status): void
    {
        try {
            // Find the existing PIX queue record for this order
            $queueItems = $this->multiPaymentQueueService->getByOrderId((int)$order->getId());
            
            $pixQueueFound = false;
            foreach ($queueItems as $queueItem) {
                if ($queueItem->getSecondaryMethodType() === MultiPaymentQueue::SECONDARY_METHOD_PIX) {
                    $pixQueueFound = true;
                    
                    // Update the queue record with card TID and status
                    $queueItem->setPrimaryTransactionId($cardTid);
                    
                    // Add error message if status is failed
                    $errorMessage = null;
                    if ($status === MultiPaymentQueue::STATUS_FAILED) {
                        $errorMessage = 'PIX cancelled due to card payment failure';
                    }
                    
                    // Use the updateStatus method to save
                    $this->multiPaymentQueueService->updateStatus($queueItem, $status, [], $errorMessage);
                    
                    $this->helper->log(
                        "CardPix - Updated PIX queue record for order {$order->getIncrementId()} with card TID: {$cardTid}, status: {$status}",
                        'cardpix_queue'
                    );
                    break;
                }
            }
            
            if (!$pixQueueFound) {
                $this->helper->log(
                    "CardPix - WARNING: No PIX queue record found for order {$order->getIncrementId()} to update with card TID: {$cardTid}",
                    'cardpix_error'
                );
            }
            
        } catch (\Exception $e) {
            $this->helper->log(
                "CardPix - Error updating PIX queue record for order {$order->getIncrementId()}: {$e->getMessage()}",
                'cardpix_error'
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
