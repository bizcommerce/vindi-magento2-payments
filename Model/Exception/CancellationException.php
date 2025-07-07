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

namespace Vindi\VP\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

class CancellationException extends LocalizedException
{
    /**
     * Constructor
     *
     * @param string $message
     * @param \Throwable|null $cause
     * @param int $code
     */
    public function __construct(string $message = '', \Throwable $cause = null, int $code = 0)
    {
        parent::__construct($cause ? $cause->getMessage() : $message, $cause, $code);
    }
}
