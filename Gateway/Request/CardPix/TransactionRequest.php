<?php
namespace Vindi\VP\Gateway\Request\CardPix;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Vindi\VP\Model\Ui\Pix\ConfigProvider as PixConfigProvider;
use Vindi\VP\Model\Ui\CreditCard\ConfigProvider as CreditCardConfigProvider;
use Vindi\VP\Helper\Data as HelperData;

/**
 * Builds multi-method payment request for Card + Pix transactions.
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
     * Builds the request payloads for Card + Pix split.
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

        $payment     = $buildSubject['payment']->getPayment();
        $order       = $payment->getOrder();

        // Retrieve split amounts
        $amountCredit = $payment->getAdditionalInformation('amount_credit');
        $amountPix    = $payment->getAdditionalInformation('amount_pix');
        $installments = $payment->getAdditionalInformation('installments');

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
        $discountItemCredit = [
            'product_id'  => $discountProductId,
            'quantity'    => 1,
            'unit_price'  => -(int)round($amountPix * 100),
            'description' => 'Discount Pix',
        ];

        $discountItemPix = [
            'product_id'  => $discountProductId,
            'quantity'    => 1,
            'unit_price'  => -(int)round($amountCredit * 100),
            'description' => 'Discount Card',
        ];

        $incrementId = $order->getIncrementId();

        // Build card transaction payload
        $payloadCard = [
            'customer_id'         => $order->getCustomerId(),
            'payment_method_code' => CreditCardConfigProvider::CARD,
            'bill_items'          => array_merge($items, [$discountItemCredit]),
            'installments'        => (int)$installments,
            'code'                => $incrementId . '-01',
        ];

        // Build Pix transaction payload
        $payloadPix = [
            'customer_id'         => $order->getCustomerId(),
            'payment_method_code' => PixConfigProvider::PIX,
            'bill_items'          => array_merge($items, [$discountItemPix]),
            'code'                => $incrementId . '-02',
        ];

        return [
            'card' => $payloadCard,
            'pix'  => $payloadPix
        ];
    }
}
