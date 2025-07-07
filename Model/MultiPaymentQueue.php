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

use Magento\Framework\Model\AbstractModel;
use Vindi\VP\Model\ResourceModel\MultiPaymentQueue as MultiPaymentQueueResource;

/**
 * Class MultiPaymentQueue
 * Model for managing multi-payment method secondary requests
 */
class MultiPaymentQueue extends AbstractModel
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_EXECUTED = 'executed';
    const STATUS_FAILED = 'failed';

    const SECONDARY_METHOD_PIX = 'pix';
    const SECONDARY_METHOD_CARD = 'card';
    const SECONDARY_METHOD_CARD2 = 'card2';
    const SECONDARY_METHOD_BANKSLIP = 'bankslip';
    const SECONDARY_METHOD_BOLEPIX = 'bolepix';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(MultiPaymentQueueResource::class);
    }

    /**
     * Get order ID
     *
     * @return int
     */
    public function getOrderId(): int
    {
        return (int)$this->getData('order_id');
    }

    /**
     * Set order ID
     *
     * @param int $orderId
     * @return $this
     */
    public function setOrderId(int $orderId): self
    {
        return $this->setData('order_id', $orderId);
    }

    /**
     * Get increment ID
     *
     * @return string
     */
    public function getIncrementId(): string
    {
        return (string)$this->getData('increment_id');
    }

    /**
     * Set increment ID
     *
     * @param string $incrementId
     * @return $this
     */
    public function setIncrementId(string $incrementId): self
    {
        return $this->setData('increment_id', $incrementId);
    }

    /**
     * Get payment method
     *
     * @return string
     */
    public function getPaymentMethod(): string
    {
        return (string)$this->getData('payment_method');
    }

    /**
     * Set payment method
     *
     * @param string $method
     * @return $this
     */
    public function setPaymentMethod(string $method): self
    {
        return $this->setData('payment_method', $method);
    }

    /**
     * Get primary transaction ID
     *
     * @return string|null
     */
    public function getPrimaryTransactionId(): ?string
    {
        return $this->getData('primary_transaction_id');
    }

    /**
     * Set primary transaction ID
     *
     * @param string|null $transactionId
     * @return $this
     */
    public function setPrimaryTransactionId(?string $transactionId): self
    {
        return $this->setData('primary_transaction_id', $transactionId);
    }

    /**
     * Get secondary method type
     *
     * @return string
     */
    public function getSecondaryMethodType(): string
    {
        return (string)$this->getData('secondary_method_type');
    }

    /**
     * Set secondary method type
     *
     * @param string $type
     * @return $this
     */
    public function setSecondaryMethodType(string $type): self
    {
        return $this->setData('secondary_method_type', $type);
    }

    /**
     * Get secondary amount
     *
     * @return float
     */
    public function getSecondaryAmount(): float
    {
        return (float)$this->getData('secondary_amount');
    }

    /**
     * Set secondary amount
     *
     * @param float $amount
     * @return $this
     */
    public function setSecondaryAmount(float $amount): self
    {
        return $this->setData('secondary_amount', $amount);
    }

    /**
     * Get request data
     *
     * @return array
     */
    public function getRequestData(): array
    {
        $data = $this->getData('request_data');
        return $data ? json_decode($data, true) : [];
    }

    /**
     * Set request data
     *
     * @param array $data
     * @return $this
     */
    public function setRequestData(array $data): self
    {
        return $this->setData('request_data', json_encode($data));
    }

    /**
     * Get status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return (string)$this->getData('status');
    }

    /**
     * Set status
     *
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self
    {
        return $this->setData('status', $status);
    }

    /**
     * Get attempts
     *
     * @return int
     */
    public function getAttempts(): int
    {
        return (int)$this->getData('attempts');
    }

    /**
     * Set attempts
     *
     * @param int $attempts
     * @return $this
     */
    public function setAttempts(int $attempts): self
    {
        return $this->setData('attempts', $attempts);
    }

    /**
     * Increment attempts
     *
     * @return $this
     */
    public function incrementAttempts(): self
    {
        return $this->setAttempts($this->getAttempts() + 1);
    }

    /**
     * Get max attempts
     *
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return (int)$this->getData('max_attempts');
    }

    /**
     * Set max attempts
     *
     * @param int $maxAttempts
     * @return $this
     */
    public function setMaxAttempts(int $maxAttempts): self
    {
        return $this->setData('max_attempts', $maxAttempts);
    }

    /**
     * Get next attempt timestamp
     *
     * @return string|null
     */
    public function getNextAttemptAt(): ?string
    {
        return $this->getData('next_attempt_at');
    }

    /**
     * Set next attempt timestamp
     *
     * @param string|null $timestamp
     * @return $this
     */
    public function setNextAttemptAt(?string $timestamp): self
    {
        return $this->setData('next_attempt_at', $timestamp);
    }

    /**
     * Get response data
     *
     * @return array
     */
    public function getResponseData(): array
    {
        $data = $this->getData('response_data');
        return $data ? json_decode($data, true) : [];
    }

    /**
     * Set response data
     *
     * @param array $data
     * @return $this
     */
    public function setResponseData(array $data): self
    {
        return $this->setData('response_data', json_encode($data));
    }

    /**
     * Get error message
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string
    {
        return $this->getData('error_message');
    }

    /**
     * Set error message
     *
     * @param string|null $message
     * @return $this
     */
    public function setErrorMessage(?string $message): self
    {
        return $this->setData('error_message', $message);
    }

    /**
     * Check if can retry
     *
     * @return bool
     */
    public function canRetry(): bool
    {
        return $this->getAttempts() < $this->getMaxAttempts();
    }

    /**
     * Mark as failed
     *
     * @param string $errorMessage
     * @return $this
     */
    public function markAsFailed(string $errorMessage): self
    {
        return $this->setStatus(self::STATUS_FAILED)
            ->setErrorMessage($errorMessage);
    }

    /**
     * Mark as completed
     *
     * @param array $responseData
     * @return $this
     */
    public function markAsCompleted(array $responseData): self
    {
        return $this->setStatus(self::STATUS_COMPLETED)
            ->setResponseData($responseData);
    }

    /**
     * Mark as processing
     *
     * @return $this
     */
    public function markAsProcessing(): self
    {
        return $this->setStatus(self::STATUS_PROCESSING);
    }

    /**
     * Get entity ID
     *
     * @return int|null
     */
    public function getId()
    {
        return $this->getData('entity_id');
    }

    /**
     * Set entity ID
     *
     * @param int $id
     * @return $this
     */
    public function setId($id)
    {
        return $this->setData('entity_id', $id);
    }
}
