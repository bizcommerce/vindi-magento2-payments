<?php

/**
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 */

namespace Vindi\VP\Block\Sales\Order\Totals;

use Magento\Framework\View\Element\Template;
use Magento\Sales\Model\Order;

/**
 * Class Interest
 *
 * @package Vindi\VP\Block\Sales\Order\Totals
 */
class Interest extends Template
{
    /**
     * @var \Magento\Framework\DataObject
     */
    protected $_source;

    /**
     * Get data (totals) source model
     *
     * @return \Magento\Framework\DataObject
     */
    public function getSource()
    {
        return $this->getParentBlock()->getSource();
    }

    /**
     * Add this total to parent
     *
     * @return $this
     */
    public function initTotals()
    {
        $source = $this->getSource();

        if ($source->getVindiInterestAmount() > 0) {
            $total = new \Magento\Framework\DataObject([
                'code'  => 'vindi_interest',
                'field' => 'vindi_interest_amount',
                'value' => $source->getVindiInterestAmount(),
                'label' => __('Interest Rate'),
            ]);

            $this->getParentBlock()->addTotalBefore($total, $this->getBeforeCondition());
        }

        return $this;
    }
}
