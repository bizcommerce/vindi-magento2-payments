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

class CancellationResult
{
    /**
     * @var string
     */
    private $status;

    /**
     * @var array
     */
    private $results;

    /**
     * @var string
     */
    private $message;

    /**
     * Constructor
     *
     * @param string $status
     * @param array $results
     * @param string $message
     */
    public function __construct(string $status, array $results, string $message)
    {
        $this->status = $status;
        $this->results = $results;
        $this->message = $message;
    }

    /**
     * Get cancellation status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get cancellation results
     *
     * @return array
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get cancellation message
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Check if cancellation was successful
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->status === CancellationService::CANCELLATION_SUCCESS;
    }

    /**
     * Check if cancellation was partial
     *
     * @return bool
     */
    public function isPartial(): bool
    {
        return $this->status === CancellationService::CANCELLATION_PARTIAL;
    }

    /**
     * Check if cancellation failed
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === CancellationService::CANCELLATION_FAILED;
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'results' => $this->results,
            'message' => $this->message
        ];
    }
}
