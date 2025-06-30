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

namespace Vindi\VP\Model;

use Vindi\VP\Model\MultiPaymentQueueFactory;
use Vindi\VP\Model\ResourceModel\MultiPaymentQueue as MultiPaymentQueueResource;
use Vindi\VP\Model\ResourceModel\MultiPaymentQueue\CollectionFactory as MultiPaymentQueueCollectionFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Class MultiPaymentQueueService
 * Service for managing multi-payment queue operations
 */
class MultiPaymentQueueService
{
    /**
     * @var MultiPaymentQueueFactory
     */
    private $multiPaymentQueueFactory;

    /**
     * @var MultiPaymentQueueResource
     */
    private $multiPaymentQueueResource;

    /**
     * @var MultiPaymentQueueCollectionFactory
     */
    private $collectionFactory;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param MultiPaymentQueueFactory $multiPaymentQueueFactory
     * @param MultiPaymentQueueResource $multiPaymentQueueResource
     * @param MultiPaymentQueueCollectionFactory $collectionFactory
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        MultiPaymentQueueFactory $multiPaymentQueueFactory,
        MultiPaymentQueueResource $multiPaymentQueueResource,
        MultiPaymentQueueCollectionFactory $collectionFactory,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->multiPaymentQueueFactory = $multiPaymentQueueFactory;
        $this->multiPaymentQueueResource = $multiPaymentQueueResource;
        $this->collectionFactory = $collectionFactory;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Add secondary payment to queue
     *
     * @param int $orderId
     * @param string $incrementId
     * @param string $paymentMethod
     * @param string $primaryTransactionId
     * @param string $secondaryMethodType
     * @param float $secondaryAmount
     * @param array $requestData
     * @param string $status
     * @return MultiPaymentQueue
     */
    public function addToQueue(
        int $orderId,
        string $incrementId,
        string $paymentMethod,
        string $primaryTransactionId,
        string $secondaryMethodType,
        float $secondaryAmount,
        array $requestData,
        string $status = MultiPaymentQueue::STATUS_PENDING
    ): MultiPaymentQueue {
        /** @var MultiPaymentQueue $queueItem */
        $queueItem = $this->multiPaymentQueueFactory->create();
        
        $queueItem->setOrderId($orderId)
            ->setIncrementId($incrementId)
            ->setPaymentMethod($paymentMethod)
            ->setPrimaryTransactionId($primaryTransactionId)
            ->setSecondaryMethodType($secondaryMethodType)
            ->setSecondaryAmount($secondaryAmount)
            ->setRequestData($requestData)
            ->setStatus($status)
            ->setAttempts(0)
            ->setMaxAttempts(3);

        try {
            $this->multiPaymentQueueResource->save($queueItem);
            $this->logger->info('Multi-payment queue item created', [
                'order_id' => $orderId,
                'increment_id' => $incrementId,
                'payment_method' => $paymentMethod,
                'secondary_method' => $secondaryMethodType,
                'secondary_amount' => $secondaryAmount
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save multi-payment queue item', [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ]);
            throw $e;
        }

        return $queueItem;
    }

    /**
     * Get pending queue items
     *
     * @param int $limit
     * @return MultiPaymentQueue[]
     */
    public function getPendingItems(int $limit = 50): array
    {
        $collection = $this->collectionFactory->create();
        $collection->getPendingItems()
            ->setPageSize($limit)
            ->setCurPage(1)
            ->setOrder('created_at', 'ASC');

        return $collection->getItems();
    }

    /**
     * Get queue items by order ID
     *
     * @param int $orderId
     * @return MultiPaymentQueue[]
     */
    public function getByOrderId(int $orderId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->getByOrderId($orderId);

        return $collection->getItems();
    }

    /**
     * Update queue item status
     *
     * @param MultiPaymentQueue $queueItem
     * @param string $status
     * @param array $responseData
     * @param string|null $errorMessage
     * @return void
     */
    public function updateStatus(
        MultiPaymentQueue $queueItem,
        string $status,
        array $responseData = [],
        ?string $errorMessage = null
    ): void {
        $queueItem->setStatus($status);
        
        if (!empty($responseData)) {
            $queueItem->setResponseData($responseData);
        }
        
        if ($errorMessage) {
            $queueItem->setErrorMessage($errorMessage);
        }

        if ($status === MultiPaymentQueue::STATUS_FAILED && $queueItem->canRetry()) {
            // Set next attempt time (exponential backoff: 2^attempts minutes)
            $delayMinutes = pow(2, $queueItem->getAttempts());
            $nextAttempt = date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"));
            $queueItem->setNextAttemptAt($nextAttempt);
        }

        try {
            $this->multiPaymentQueueResource->save($queueItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update multi-payment queue item', [
                'error' => $e->getMessage(),
                'queue_item_id' => $queueItem->getId()
            ]);
            throw $e;
        }
    }

    /**
     * Mark item as processing
     *
     * @param MultiPaymentQueue $queueItem
     * @return void
     */
    public function markAsProcessing(MultiPaymentQueue $queueItem): void
    {
        $queueItem->markAsProcessing()->incrementAttempts();
        
        try {
            $this->multiPaymentQueueResource->save($queueItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark queue item as processing', [
                'error' => $e->getMessage(),
                'queue_item_id' => $queueItem->getId()
            ]);
            throw $e;
        }
    }

    /**
     * Generate secondary transaction identifier
     *
     * @param string $incrementId
     * @param string $suffix
     * @return string
     */
    public function generateSecondaryTransactionId(string $incrementId, string $suffix = '02'): string
    {
        return $incrementId . '-' . $suffix;
    }

    /**
     * Generate primary transaction identifier
     *
     * @param string $incrementId
     * @param string $suffix
     * @return string
     */
    public function generatePrimaryTransactionId(string $incrementId, string $suffix = '01'): string
    {
        return $incrementId . '-' . $suffix;
    }
}
