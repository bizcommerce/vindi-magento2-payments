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
 * Class CardPix
 * Block for displaying Card + PIX payment information
 */
class CardPix extends AbstractInfo
{
    /**
     * @var string
     */
    protected $_template = 'Vindi_VP::payment/info/cardpix.phtml';

    /**
     * Get card information
     *
     * @return array
     */
    public function getCardInfo()
    {
        $info = $this->getInfo();
        return [
            'tid' => $info->getAdditionalInformation('card_payment_tid'),
            'installments' => $info->getAdditionalInformation('card_installments') ?: $info->getAdditionalInformation('installments'),
            'amount' => $info->getAdditionalInformation('card_amount') ?: $info->getAdditionalInformation('amount_credit')
        ];
    }

    /**
     * Get PIX information from multi_payment_info or additional information
     *
     * @return array
     */
    public function getPixInfo()
    {
        $info = $this->getInfo();
        
        // First, try to get PIX data from multi_payment_info (processed via CRON)
        $multiPaymentInfo = $info->getAdditionalInformation('multi_payment_info') ?: [];
        $pixData = null;
        
        foreach ($multiPaymentInfo as $paymentInfo) {
            if (isset($paymentInfo['method']) && 
                (stripos($paymentInfo['method'], 'pix') !== false || 
                 stripos($paymentInfo['method'], 'PIX') !== false)) {
                $pixData = $paymentInfo;
                break;
            }
        }
        
        // If found in multi_payment_info, use that data
        if ($pixData) {
            return [
                'tid' => $pixData['transaction_id'] ?? '',
                'status' => $pixData['status'] ?? '',
                'amount' => $pixData['amount'] ?? $info->getAdditionalInformation('amount_pix'),
                'processed_at' => $pixData['date'] ?? '',
                'qr_code' => $info->getAdditionalInformation('pix_qr_code') ?? '',
                'url' => $info->getAdditionalInformation('pix_url') ?? ''
            ];
        }
        
        // Fallback to direct additional information (for backwards compatibility)
        return [
            'tid' => $info->getAdditionalInformation('pix_payment_tid') ?? '',
            'status' => $info->getAdditionalInformation('pix_status') ?? '',
            'amount' => $info->getAdditionalInformation('amount_pix') ?? '',
            'processed_at' => '',
            'qr_code' => $info->getAdditionalInformation('pix_qr_code') ?? '',
            'url' => $info->getAdditionalInformation('pix_url') ?? ''
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
     * Check if PIX has been processed (has transaction data)
     *
     * @return bool
     */
    public function hasPixData()
    {
        $pixInfo = $this->getPixInfo();
        return !empty($pixInfo['tid']) || !empty($pixInfo['qr_code']) || !empty($pixInfo['url']);
    }

    /**
     * Check if PIX is still pending processing
     *
     * @return bool
     */
    public function isPixPending()
    {
        $status = $this->getPaymentStatus();
        return strpos($status, 'pix_pending') !== false || 
               (!$this->hasPixData() && strpos($status, 'card_approved') !== false);
    }

    /**
     * Format payment status for display
     *
     * @param string $status
     * @return string
     */
    public function formatPaymentStatus($status)
    {
        $statusLabels = [
            'card_approved_pix_pending' => 'Card Approved, PIX Pending Processing',
            'card_failed_pix_cancelled' => 'Card Failed, PIX Cancelled',
            'card_approved_pix_processed' => 'Card Approved, PIX Processed',
            'card_approved_pix_failed' => 'Card Approved, PIX Failed'
        ];

        return $statusLabels[$status] ?? $status;
    }

    /**
     * Format PIX status for display
     *
     * @param string $status
     * @return string
     */
    public function formatPixStatus($status)
    {
        $statusLabels = [
            'approved' => 'Approved',
            'failed' => 'Failed',
            'pending' => 'Pending',
            'processing' => 'Processing',
            'executed' => 'Executed',
            'completed' => 'Completed'
        ];

        return $statusLabels[$status] ?? $status;
    }

    /**
     * Returns label
     *
     * @param string $field
     * @return \Magento\Framework\Phrase
     */
    protected function getLabel($field)
    {
        $labels = [
            'cc_type' => __('Card Brand'),
            'cc_owner' => __('Card Owner'),
            'cc_last_4' => __('Card Number'),
            'installments' => __('Installments'),
            'card_payment_tid' => __('Card Transaction ID'),
            'card_installments' => __('Card Installments'),
            'card_amount' => __('Card Amount'),
            'amount_credit' => __('Credit Card Amount'),
            'amount_pix' => __('PIX Amount'),
            'payment_status' => __('Payment Status'),
            'pix_payment_tid' => __('PIX Transaction ID'),
            'pix_status' => __('PIX Status'),
            'pix_amount' => __('PIX Amount'),
            'pix_qr_code' => __('PIX QR Code'),
            'pix_url' => __('PIX Payment URL'),
            'tid' => __('Transaction ID'),
            'transaction_id' => __('Transaction ID'),
            'payment_method_id' => __('Payment Method ID'),
            'payment_method_name' => __('Payment Method Name')
        ];

        return isset($labels[$field]) ? $labels[$field] : parent::getLabel($field);
    }

    /**
     * Format specific values for display
     *
     * @param string $field
     * @param string|array $value
     * @return string
     */
    protected function getValueView($field, $value)
    {
        switch ($field) {
            case 'cc_last_4':
                return '****' . $value;
            case 'cc_type':
                return ucfirst((string)$value);
            case 'installments':
            case 'card_installments':
                return $value . 'x';
            case 'card_amount':
            case 'amount_credit':
            case 'amount_pix':
                return 'R$ ' . number_format((float)$value, 2, ',', '.');
            default:
                return parent::getValueView($field, $value);
        }
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
        
        // Dados de cartão
        if ($info->getCcType()) {
            $data[(string)__('Credit Card Type')] = $this->getCcTypeName();
        }
        if ($info->getCcOwner()) {
            $data[(string)__('Credit Card Owner')] = $info->getCcOwner();
        }
        if ($info->getCcLast4()) {
            $data[(string)__('Credit Card Number')] = sprintf('xxxx-%s', $info->getCcLast4());
        }
        
        // Dados específicos do método
        if ($installments = $info->getAdditionalInformation('vindi_installments')) {
            $data[(string)__('Installments')] = $installments;
        }
        
        // Informações de pagamento CardPix
        $cardInfo = $this->getCardInfo();
        if ($cardInfo['tid']) {
            $data[(string)__('Card Transaction ID')] = $cardInfo['tid'];
        }
        
        $pixInfo = $this->getPixInfo();
        if ($pixInfo['tid']) {
            $data[(string)__('PIX Transaction ID')] = $pixInfo['tid'];
        }
        
        $paymentStatus = $this->getPaymentStatus();
        if ($paymentStatus) {
            $data[(string)__('Payment Status')] = $this->formatPaymentStatus($paymentStatus);
        }
        
        $transport = new \Magento\Framework\DataObject($data);
        return parent::_prepareSpecificInformation($transport);
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
