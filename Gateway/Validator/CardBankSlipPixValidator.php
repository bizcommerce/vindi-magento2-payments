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
 * Class CardBankSlipPixValidator
 * Validates the response for BankSlip + Pix payment method
 */
class CardBankSlipPixValidator extends AbstractValidator
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
            $bankSlipErrorMessage = $response['bankslip_response']['message'] ?? 'Unknown error in bank slip payment';
            $pixErrorMessage = $response['pix_response']['message'] ?? '';

            $errorMessages = [$bankSlipErrorMessage];
            if (!empty($pixErrorMessage)) {
                $errorMessages[] = $pixErrorMessage;
            }

            return $this->createResult(false, $errorMessages);
        }

        $bankSlipValid = $this->validateBankSlipResponse($response['bankslip_response'] ?? []);
        if (!$bankSlipValid['isValid']) {
            return $this->createResult(
                false,
                [__('Bank slip payment validation error: %1', implode(', ', $bankSlipValid['failsDescription']))]
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
     * Validate the bank slip payment response
     *
     * @param array $response
     * @return array
     */
    private function validateBankSlipResponse(array $response): array
    {
        $result = ['isValid' => true, 'failsDescription' => []];

        if (!isset($response['status_id'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing status_id in bank slip response');
        }

        if (isset($response['status_id']) && !in_array($response['status_id'], ['3', '4'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __(
                'Invalid status in bank slip response: %1',
                $response['status_id']
            );
        }

        if (!isset($response['bank_slip']) || !isset($response['bank_slip']['url'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing bank slip URL in response');
        }

        if (!isset($response['tid'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing transaction ID in bank slip response');
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

        if (!isset($response['pix_code'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing PIX code in response');
        }

        if (!isset($response['tid'])) {
            $result['isValid'] = false;
            $result['failsDescription'][] = __('Missing transaction ID in PIX response');
        }

        return $result;
    }
}
