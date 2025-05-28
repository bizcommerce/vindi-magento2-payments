<?php
namespace Vindi\VP\Gateway\Request\CardCard;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Vindi\VP\Model\Ui\CreditCard\ConfigProvider as CreditCardConfigProvider;
use Vindi\VP\Helper\Data as HelperData;

/**
 * Builds multi-method payment request for two Card transactions.
 */
class TransactionRequest implements BuilderInterface
{
    /**
     * @var HelperData
     */
    private $helperData;

    /**
     * Constructor
     *
     * @param HelperData $helperData
     */
    public function __construct(
        HelperData $helperData
    ) {
        $this->helperData = $helperData;
    }

    /**
     * Builds the request payloads for two Card split.
     *
     * @param array $buildSubject
     * @return array
     * @throws \InvalidArgumentException
     */
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        $payment           = $buildSubject['payment']->getPayment();
        $order             = $payment->getOrder();

        // Retrieve split amounts
        $amountFirstCard   = $payment->getAdditionalInformation('amount_credit');
        $amountSecondCard  = $payment->getAdditionalInformation('amount_second_card');
        $installments      = $payment->getAdditionalInformation('installments');

        // Prepare line items from order
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'product_id'  => $item->getProductId(),
                'quantity'    => (int)$item->getQtyOrdered(),
                'unit_price'  => (int)round($item->getPrice() * 100),
                'description' => $item->getName(),
            ];
        }

        // Discount item product ID
        $discountProductId = $this->helperData->getMultiPaymentDiscountProductId();

        // Discount items to adjust split values
        $discountItemFirst  = [
            'product_id'  => $discountProductId,
            'quantity'    => 1,
            'unit_price'  => -(int)round($amountSecondCard * 100),
            'description' => 'Discount Second Card',
        ];

        $discountItemSecond = [
            'product_id'  => $discountProductId,
            'quantity'    => 1,
            'unit_price'  => -(int)round($amountFirstCard * 100),
            'description' => 'Discount First Card',
        ];

        $incrementId = $order->getIncrementId();

        // Build first card transaction payload
        $payloadFirstCard = [
            'customer_id'         => $order->getCustomerId(),
            'payment_method_code' => CreditCardConfigProvider::CODE,
            'bill_items'          => array_merge($items, [$discountItemFirst]),
            'installments'        => (int)$installments,
            'code'                => $incrementId . '-01',
        ];

        // Build second card transaction payload
        $payloadSecondCard = [
            'customer_id'         => $order->getCustomerId(),
            'payment_method_code' => CreditCardConfigProvider::CODE,
            'bill_items'          => array_merge($items, [$discountItemSecond]),
            'installments'        => (int)$installments,
            'code'                => $incrementId . '-02',
        ];

        return [
            'first_card'  => $payloadFirstCard,
            'second_card' => $payloadSecondCard
        ];
    }
}
