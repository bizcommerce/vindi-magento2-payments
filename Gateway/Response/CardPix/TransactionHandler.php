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

        $this->helper->log(
            "CardPix TransactionHandler - Processing order {$order->getIncrementId()}",
            'cardpix_handler'
        );

        if (isset($response['transaction'])) {
            $cardTransaction = $response['transaction'];
            $cardTid = $cardTransaction['payment']['tid'] ?? '';
            $cardStatus = $cardTransaction['status_id'] ?? '';

            $payment->setAdditionalInformation('card_payment_tid', $cardTid);
            $payment->setAdditionalInformation('card_status', $cardStatus);
            $payment->setAdditionalInformation('card_installments', $payment->getAdditionalInformation('installments'));
            $payment->setAdditionalInformation('card_amount', $payment->getAdditionalInformation('amount_credit'));

            $payment->setTransactionId($cardTid);
            $payment->setIsTransactionClosed(false);

            if ($this->isSuccessfulResponse($cardTransaction)) {
                $this->updatePixQueueRecord($order, $cardTid, MultiPaymentQueue::STATUS_PENDING);
                
                $payment->setAdditionalInformation('payment_status', 'card_approved_pix_pending');
                
                $this->helper->log(
                    "CardPix - Card payment successful for order {$order->getIncrementId()}, TID: {$cardTid}. PIX remains pending for processing.",
                    'cardpix_handler'
                );
            } else {
                $this->updatePixQueueRecord($order, $cardTid, MultiPaymentQueue::STATUS_FAILED);
                
                $payment->setAdditionalInformation('payment_status', 'card_failed_pix_cancelled');
                
                $this->helper->log(
                    "CardPix - Card payment failed for order {$order->getIncrementId()}, Status: {$cardStatus}. PIX cancelled due to card failure.",
                    'cardpix_handler'
                );
                
                $payment->setIsTransactionPending(false);
                $payment->setIsTransactionClosed(true);
            }
        }

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
            $queueItems = $this->multiPaymentQueueService->getByOrderId((int)$order->getId());
            
            $pixQueueFound = false;
            foreach ($queueItems as $queueItem) {
                if ($queueItem->getSecondaryMethodType() === MultiPaymentQueue::SECONDARY_METHOD_PIX) {
                    $pixQueueFound = true;
                    
                    $queueItem->setPrimaryTransactionId($cardTid);
                    
                    $errorMessage = null;
                    if ($status === MultiPaymentQueue::STATUS_FAILED) {
                        $errorMessage = 'PIX cancelled due to card payment failure';
                    }
                    
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
        $statusId = $response['status_id'] ?? null;
        $tid = $response['payment']['tid'] ?? null;
        
        return !empty($tid) && in_array($statusId, ['3', '4']);
    }
}
