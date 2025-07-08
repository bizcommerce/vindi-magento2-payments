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
use Vindi\VP\Model\MultiPaymentQueue;

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
     * @var MultiPaymentQueueService
     */
    protected $multiPaymentQueueService;

    /**
     * @var EventManagerInterface
     */
    protected $eventManager;

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
     * Handles response for Card + Card payment method (only processes card1, queues card2)
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
            "CardCard TransactionHandler - Processing order {$order->getIncrementId()}",
            'cardcard_handler'
        );
        
        $this->helper->log(
            "CardCard TransactionHandler - Response structure: " . json_encode(array_keys($response)),
            'cardcard_debug'
        );
        if (isset($response['transaction'])) {
            $this->helper->log(
                "CardCard TransactionHandler - Transaction keys: " . json_encode(array_keys($response['transaction'])),
                'cardcard_debug'
            );
        }

        $card1Transaction = null;
        
        if (isset($response['transaction']['data_response']['transaction'])) {
            $card1Transaction = $response['transaction']['data_response']['transaction'];
        } elseif (isset($response['transaction']['transaction'])) {
            $card1Transaction = $response['transaction']['transaction'];
        } elseif (isset($response['transaction'])) {
            $potentialTransaction = $response['transaction'];
            if (isset($potentialTransaction['status_id'])) {
                $card1Transaction = $potentialTransaction;
            }
        } elseif (isset($response['data_response']['transaction'])) {
            $card1Transaction = $response['data_response']['transaction'];
        }
        
        if ($card1Transaction) {
            $card1Tid = $card1Transaction['payment']['tid'] ?? '';
            $card1Status = $card1Transaction['status_id'] ?? '';

            $payment->setAdditionalInformation('card1_payment_tid', $card1Tid);
            $payment->setAdditionalInformation('card1_status', $card1Status);
            $payment->setAdditionalInformation('card1_installments', $payment->getAdditionalInformation('installments'));
            $payment->setAdditionalInformation('card1_amount', $payment->getAdditionalInformation('amount_card1'));

            $payment->setTransactionId($card1Tid);
            $payment->setIsTransactionClosed(false);


            if ($this->isSuccessfulResponse($card1Transaction)) {
                $this->updateCard2QueueRecord($order, $card1Tid, MultiPaymentQueue::STATUS_PENDING);

                $payment->setAdditionalInformation('payment_status', 'card1_approved_card2_pending');

                $this->helper->log(
                    "CardCard - Card1 payment successful for order {$order->getIncrementId()}, TID: {$card1Tid}. Card2 remains pending for processing.",
                    'cardcard_handler'
                );
                
                $payment->setIsTransactionPending(true);
                $payment->setIsTransactionClosed(false);
            } else {
                $this->updateCard2QueueRecord($order, $card1Tid, MultiPaymentQueue::STATUS_FAILED);

                $payment->setAdditionalInformation('payment_status', 'card1_failed_card2_cancelled');

                $this->helper->log(
                    "CardCard - Card1 payment failed for order {$order->getIncrementId()}, Status: {$card1Status}. Card2 cancelled due to card1 failure.",
                    'cardcard_handler'
                );

                $payment->setIsTransactionPending(false);
                $payment->setIsTransactionClosed(true);
            }
        } else {
            $this->helper->log(
                "CardCard - No transaction data found in response for order {$order->getIncrementId()}",
                'cardcard_error'
            );
        }

        $payment->setAdditionalInformation('vindi_response', $this->serializer->serialize($response));
    }

    /**
     * Update existing card2 queue record with card1 transaction ID and status
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $card1Tid
     * @param string $status
     * @return void
     */
    private function updateCard2QueueRecord($order, string $card1Tid, string $status): void
    {
        try {
            $queueItems = $this->multiPaymentQueueService->getByOrderId((int)$order->getId());

            $card2QueueFound = false;
            foreach ($queueItems as $queueItem) {
                if ($queueItem->getSecondaryMethodType() === MultiPaymentQueue::SECONDARY_METHOD_CARD2) {
                    $card2QueueFound = true;

                    $queueItem->setPrimaryTransactionId($card1Tid);

                    $errorMessage = null;
                    if ($status === MultiPaymentQueue::STATUS_FAILED) {
                        $errorMessage = 'Card2 cancelled due to card1 payment failure';
                    }

                    $this->multiPaymentQueueService->updateStatus($queueItem, $status, [], $errorMessage);

                    $this->helper->log(
                        "CardCard - Updated card2 queue record for order {$order->getIncrementId()} with card1 TID: {$card1Tid}, status: {$status}",
                        'cardcard_queue'
                    );
                    break;
                }
            }

            if (!$card2QueueFound) {
                $this->helper->log(
                    "CardCard - WARNING: No card2 queue record found for order {$order->getIncrementId()} to update with card1 TID: {$card1Tid}",
                    'cardcard_error'
                );
            }

        } catch (\Exception $e) {
            $this->helper->log(
                "CardCard - Error updating card2 queue record for order {$order->getIncrementId()}: {$e->getMessage()}",
                'cardcard_error'
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

        $this->helper->log(
            "CardCard isSuccessfulResponse - StatusId: {$statusId}, TID: {$tid}",
            'cardcard_debug'
        );

        if ($statusId == '4' || $statusId == 4) {
            $this->helper->log("CardCard isSuccessfulResponse - Status 4 SUCCESS", 'cardcard_debug');
            return true;
        } elseif ($statusId == '3' || $statusId == 3) {
            $success = !empty($tid);
            $this->helper->log("CardCard isSuccessfulResponse - Status 3, Success: " . ($success ? 'true' : 'false'), 'cardcard_debug');
            return $success;
        }
        
        $this->helper->log("CardCard isSuccessfulResponse - FAILURE - Invalid status", 'cardcard_debug');
        return false;
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
            $this->helper->log('Card saved for customer: ' . $customerId);
        } catch (\Exception $e) {
            $this->helper->log('Error saving card: ' . $e->getMessage());
        }
    }
}
