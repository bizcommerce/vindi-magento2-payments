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
 * Class CardPixValidator
 * Validates the response for Card + Pix payment method
 */
class CardPixValidator extends AbstractValidator
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

        if (isset($response['error']) && $response['error'] === true) {
            $cardErrorMessage = $response['card_response']['message'] ?? 'Unknown error in card payment';
            $pixErrorMessage = $response['pix_response']['message'] ?? '';

            $errorMessages = [$cardErrorMessage];
            if (!empty($pixErrorMessage)) {
                $errorMessages[] = $pixErrorMessage;
            }

            return $this->createResult(false, $errorMessages);
        }

        $cardValid = $this->validateCardResponse($response['card_response'] ?? []);
        if (!$cardValid['isValid']) {
            return $this->createResult(
                false,
                [__('Card payment validation error: %1', implode(', ', $cardValid['failsDescription']))]
            );
        }

        $pixValid = $this->validatePixResponse($response['pix_response'] ?? []);
        if (!$pixValid['isValid']) {
            return $this->createResult(
                false,
                [__('PIX payment validation error: %1', implode(', ', $pixValid['failsDescription']))]
            );
        }

        return $this->createResult(true);
    }

    /**
     * Validate the card payment response
     *
     * @param array $response
     * @return array
     */
    private function validateCardResponse(array $response): array
    {
        $result = ['isValid' => true, 'failsDescription' => []];

        if (!isset($response['status_id'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing status_id in card response');
        }

        if (isset($response['status_id']) && !in_array($response['status_id'], ['3', '4'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __(
                'Invalid status in card response: %1',
                $response['status_id']
            );
        }

        if (!isset($response['payment']['tid'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing transaction ID in card response');
        }

        return $result;
    }

    /**
     * Validate the PIX payment response
     *
     * @param array $response
     * @return array
     */
    private function validatePixResponse(array $response): array
    {
        $result = ['isValid' => true, 'failsDescription' => []];

        if (!isset($response['status_id'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing status_id in PIX response');
        }

        if (isset($response['status_id']) && !in_array($response['status_id'], ['3', '4'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __(
                'Invalid status in PIX response: %1',
                $response['status_id']
            );
        }

        if (!isset($response['payment']['tid'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing transaction ID in PIX response');
        }

        if (!isset($response['payment']['pix_code'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing PIX code in response');
        }

        return $result;
    }
}
