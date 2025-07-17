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

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Model\Config;

/**
 * Class CardCard
 * Block for displaying Card + Card payment information
 */
class CardCard extends AbstractInfo
{
    /**
     * @var string
     */
    protected $_template = 'Vindi_VP::payment/info/cardcard.phtml';

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
     * Prepare specific information for display
     *
     * @param null $transport
     * @return \Magento\Framework\DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $info = $this->getInfo();
        $data = [];
        
        if ($info->getCcType()) {
            $data[(string)__('Credit Card Type')] = $this->getCcTypeName();
        }
        if ($info->getCcOwner()) {
            $data[(string)__('Credit Card Owner')] = $info->getCcOwner();
        }
        if ($info->getCcLast4()) {
            $data[(string)__('Credit Card Number')] = sprintf('xxxx-%s', $info->getCcLast4());
        }
        
        if ($installments = $info->getAdditionalInformation('vindi_installments')) {
            $data[(string)__('Installments')] = $installments;
        }
        
        $paymentStatus = $this->getPaymentStatus();
        if ($paymentStatus) {
            $data[(string)__('Payment Status')] = $this->formatPaymentStatus($paymentStatus);
        }
        
        $card1Info = $this->getCard1Info();
        if ($card1Info['tid']) {
            $data[(string)__('Card 1 Transaction ID')] = $card1Info['tid'];
        }
        
        $card2Info = $this->getCard2Info();
        if ($card2Info['tid']) {
            $data[(string)__('Card 2 Transaction ID')] = $card2Info['tid'];
        }
        
        $transport = new \Magento\Framework\DataObject($data);
        return parent::_prepareSpecificInformation($transport);
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

    /**
     * Retrieve credit card type name
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCcTypeName()
    {
        $types = $this->paymentConfig->getCcTypes();
        $ccType = $this->getInfo()->getCcType();
        if (isset($types[$ccType])) {
            return $types[$ccType];
        }
        return empty($ccType) ? __('N/A') : __(ucwords($ccType));
    }
}
