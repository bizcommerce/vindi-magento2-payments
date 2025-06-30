<?php

/**
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 *
 */

namespace Vindi\VP\Observer;

use Vindi\VP\Helper\Data;
use Vindi\VP\Helper\Installments;
use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Model\Quote\Payment;

class CreditCardAssignObserver extends AbstractDataAssignObserver
{
    /** @var Data */
    protected $helper;

    /** @var Session  */
    protected $checkoutSession;

    /** @var Installments  */
    protected $installmentsHelper;

    /** @var Json  */
    protected $json;

    public function __construct(
        Session $checkoutSession,
        Data $helper,
        Installments $installmentsHelper,
        Json $json
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->helper = $helper;
        $this->installmentsHelper = $installmentsHelper;
        $this->json = $json;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        $data = $this->readDataArgument($observer);

        /** @var array $additionalData */
        $additionalData = $data->getAdditionalData();

        if (!empty($additionalData)) {
            // Recupera o modelo de pagamento
            /** @var Payment $paymentInfo */
            $paymentInfo = $this->readPaymentModelArgument($observer);
            $method = $paymentInfo->getMethod();

            // Métodos que precisam receber os dados do cartão
            $metodosQuePrecisamDeCartao = [
                'vindi_vp_cc',
                'vindi_vp_cardpix',
                'vindi_vp_cardbankslippix',
                'vindi_vp_cardcard',
                // Adicione outros métodos multimeios aqui se necessário
            ];

            if (isset($additionalData['cc_number']) && in_array($method, $metodosQuePrecisamDeCartao)) {
                $installments = $additionalData['installments'] ?? 1;
                $ccOwner = $additionalData['cc_owner'] ?? null;
                $ccType = $additionalData['cc_type'] ?? null;
                $ccLast4 = substr((string) $additionalData['cc_number'], -4);
                $ccBin = substr((string) $additionalData['cc_number'], 0, 6);
                $ccExpMonth = $additionalData['cc_exp_month'] ?? null;
                $ccExpYear = $additionalData['cc_exp_year'] ?? null;
                $paymentProfile = $additionalData["payment_profile"] ?? null;
                $saveCard = $additionalData['save_card'] ?? 0;

                $this->updateInterest((int) $installments);

                $paymentInfo->addData([
                    'cc_type' => $ccType,
                    'cc_owner' => $ccOwner,
                    'cc_number' => $additionalData['cc_number'],
                    'cc_last_4' => $ccLast4,
                    'cc_cid' => $additionalData['cc_cid'],
                    'cc_exp_month' => $ccExpMonth,
                    'cc_exp_year' => $ccExpYear
                ]);

                $paymentInfo->setAdditionalInformation('installments', $installments);
                $paymentInfo->setAdditionalInformation('cc_installments', $installments);
                $paymentInfo->setAdditionalInformation('cc_bin', $ccBin);
                $paymentInfo->setAdditionalInformation('payment_method', $this->helper->getMethodName($ccType));
                $paymentInfo->setAdditionalInformation('payment_profile', $paymentProfile);
                $paymentInfo->setAdditionalInformation('save_card', $saveCard);
                $paymentInfo->setAdditionalInformation('cc_cid', $additionalData['cc_cid'] ?? null);

                // Capturar valores específicos para multimeios
                if (in_array($method, ['vindi_vp_cardpix', 'vindi_vp_cardbankslippix', 'vindi_vp_cardcard'])) {
                    $amountCredit = $additionalData['amount_credit'] ?? 0;
                    $amountPix = $additionalData['amount_pix'] ?? 0;
                    
                    $paymentInfo->setAdditionalInformation('amount_credit', (float)$amountCredit);
                    $paymentInfo->setAdditionalInformation('amount_pix', (float)$amountPix);
                }

                // CVV é sempre obrigatório para todos os cartões
                $paymentInfo->setAdditionalInformation('cc_cid_required', true);
            }

            // Novo: tratamento do segundo cartão para CardCard
            if ($method === 'vindi_vp_cardcard' && isset($additionalData['cc_number_2'])) {
                $installments2 = $additionalData['installments_2'] ?? 1;
                $ccOwner2 = $additionalData['cc_owner_2'] ?? null;
                $ccType2 = $additionalData['cc_type_2'] ?? null;
                $ccLast4_2 = substr((string) $additionalData['cc_number_2'], -4);
                $ccBin2 = substr((string) $additionalData['cc_number_2'], 0, 6);
                $ccExpMonth2 = $additionalData['cc_exp_month_2'] ?? null;
                $ccExpYear2 = $additionalData['cc_exp_year_2'] ?? null;
                $paymentProfile2 = $additionalData['second_payment_profile'] ?? null;
                $saveCard2 = $additionalData['save_card_2'] ?? 0;

                $paymentInfo->setAdditionalInformation('installments_2', $installments2);
                $paymentInfo->setAdditionalInformation('cc_installments_2', $installments2);
                $paymentInfo->setAdditionalInformation('cc_bin_2', $ccBin2);
                $paymentInfo->setAdditionalInformation('payment_method_2', $this->helper->getMethodName($ccType2));
                $paymentInfo->setAdditionalInformation('payment_profile_2', $paymentProfile2);
                $paymentInfo->setAdditionalInformation('save_card_2', $saveCard2);
                $paymentInfo->setAdditionalInformation('cc_type_2', $ccType2);
                $paymentInfo->setAdditionalInformation('cc_owner_2', $ccOwner2);
                $paymentInfo->setAdditionalInformation('cc_number_2', $additionalData['cc_number_2']);
                $paymentInfo->setAdditionalInformation('cc_last_4_2', $ccLast4_2);
                $paymentInfo->setAdditionalInformation('cc_cid_2', $additionalData['cc_cid_2'] ?? null);
                $paymentInfo->setAdditionalInformation('cc_exp_month_2', $ccExpMonth2);
                $paymentInfo->setAdditionalInformation('cc_exp_year_2', $ccExpYear2);

                // CVV é sempre obrigatório para o segundo cartão também
                $paymentInfo->setAdditionalInformation('cc_cid_2_required', true);
            }
        }
    }

    /**
     * Update interest and collect totals
     *
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    protected function updateInterest(int $installments): void
    {
        $this->checkoutSession->setData('vindi_installments', $installments);
        $quote = $this->checkoutSession->getQuote();
        $quote->setTotalsCollectedFlag(false)->collectTotals();
    }
}
