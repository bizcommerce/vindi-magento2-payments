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

namespace Vindi\VP\Block\Info;

/**
 * Class CardCard
 * Block for displaying Card + Card payment information
 */
class CardCard extends AbstractInfo
{
    /**
     * @var string
     */
    protected $_template = 'Vindi_VP::info/cardcard.phtml';

    /**
     * Returns label
     *
     * @param string $field
     * @return \Magento\Framework\Phrase
     */
    protected function getLabel($field)
    {
        $labels = [
            'card1_payment_tid' => __('Card 1 Transaction ID'),
            'card1_status' => __('Card 1 Status'),
            'card1_installments' => __('Card 1 Installments'),
            'card1_amount' => __('Card 1 Amount'),
            'card2_payment_tid' => __('Card 2 Transaction ID'),
            'card2_status' => __('Card 2 Status'),
            'card2_installments' => __('Card 2 Installments'),
            'card2_amount' => __('Card 2 Amount'),
            'payment_status' => __('Payment Status'),
            'tid' => __('Transaction ID')
        ];

        return $labels[$field] ?? parent::getLabel($field);
    }

    /**
     * Get payment method title
     *
     * @return string
     */
    public function getMethodTitle()
    {
        return __('Card + Card Payment');
    }

    /**
     * Get card 1 information
     *
     * @return array
     */
    public function getCard1Info()
    {
        $info = $this->getInfo();
        return [
            'tid' => $info->getAdditionalInformation('card1_payment_tid'),
            'status' => $info->getAdditionalInformation('card1_status'),
            'installments' => $info->getAdditionalInformation('card1_installments'),
            'amount' => $info->getAdditionalInformation('card1_amount')
        ];
    }

    /**
     * Get card 2 information
     *
     * @return array
     */
    public function getCard2Info()
    {
        $info = $this->getInfo();
        return [
            'tid' => $info->getAdditionalInformation('card2_payment_tid'),
            'status' => $info->getAdditionalInformation('card2_status'),
            'installments' => $info->getAdditionalInformation('card2_installments'),
            'amount' => $info->getAdditionalInformation('card2_amount')
        ];
    }

    /**
     * Get overall payment status
     *
     * @return string
     */
    public function getPaymentStatus()
    {
        return $this->getInfo()->getAdditionalInformation('payment_status') ?? '';
    }

    /**
     * Format payment status for display
     *
     * @param string $status
     * @return \Magento\Framework\Phrase
     */
    public function formatPaymentStatus($status)
    {
        $statusLabels = [
            'card1_approved_card2_pending' => __('Card 1 Approved, Card 2 Pending'),
            'card1_failed_card2_cancelled' => __('Card 1 Failed, Card 2 Cancelled'),
            'both_cards_approved' => __('Both Cards Approved'),
            'card1_approved_card2_failed' => __('Card 1 Approved, Card 2 Failed')
        ];

        return $statusLabels[$status] ?? __($status);
    }
}
