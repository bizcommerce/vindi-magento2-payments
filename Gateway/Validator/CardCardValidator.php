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

namespace Vindi\VP\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;

/**
 * Class CardCardValidator
 * Validates the response for Card + Card payment method
 */
class CardCardValidator extends AbstractValidator
{
    /**
     * Validates the response
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject): ResultInterface
    {
        if (!isset($validationSubject['response'])) {
            return $this->createResult(false, [__('Response does not exist')]);
        }

        $response = $validationSubject['response'];

        error_log("CardCardValidator - Response structure: " . json_encode(array_keys($response)));
        error_log("CardCardValidator - Full response: " . json_encode($response));

        if (isset($response['error']) && $response['error'] === true) {
            $errorMessage = $response['message'] ?? 'Unknown error in CardCard payment';
            return $this->createResult(false, [$errorMessage]);
        }

        if (isset($response['transaction'])) {
            error_log("CardCardValidator - Transaction structure: " . json_encode(array_keys($response['transaction'])));
            $card1Valid = $this->validateCardResponse($response['transaction'], 'first');
            if (!$card1Valid['isValid']) {
                error_log("CardCardValidator - Validation failed: " . implode(', ', $card1Valid['failsDescription']));
                return $this->createResult(
                    false,
                    [__('First card payment validation error: %1', implode(', ', $card1Valid['failsDescription']))]
                );
            }
        } else {
            error_log("CardCardValidator - No transaction data found in response");
            return $this->createResult(false, [__('No transaction data found in response')]);
        }

        error_log("CardCardValidator - Validation successful");
        return $this->createResult(true);
    }

    /**
     * Validate the card payment response
     *
     * @param array $response
     * @param string $cardPosition
     * @return array
     */
    private function validateCardResponse(array $response, string $cardPosition): array
    {
        $result = ['isValid' => true, 'failsDescription' => []];

        $transaction = $response;
        if (isset($response['data_response']['transaction'])) {
            $transaction = $response['data_response']['transaction'];
        }

        if (!isset($transaction['status_id'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing status_id in %1 card response', $cardPosition);
        }

        if (isset($transaction['status_id']) && !in_array($transaction['status_id'], ['3', '4'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __(
                'Invalid status in %1 card response: %2',
                $cardPosition,
                $transaction['status_id']
            );
        }

        if (!isset($transaction['payment']['tid'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing transaction ID in %1 card response', $cardPosition);
        }

        return $result;
    }
}
