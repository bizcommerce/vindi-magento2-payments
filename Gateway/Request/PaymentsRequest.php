<?php

/**
 *
 *
 *
 *
 *
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_VP
 *
 *
 */

namespace Vindi\VP\Gateway\Request;

use Vindi\VP\Gateway\Http\Client\Api;
use Vindi\VP\Helper\Data;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;

class PaymentsRequest
{
    /**
     * @var ConfigInterface
     */
    protected $config;

    /**
     * @var DateTime
     */
    protected $date;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var ManagerInterface
     */
    protected $eventManager;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $categoryRepository;

    /**
     * @var string
     */
    protected $currencyCode;

    /**
     * @var Api
     */
    protected $api;

    public function __construct(
        ManagerInterface $eventManager,
        Data $helper,
        DateTime $date,
        ConfigInterface $config,
        CustomerSession $customerSession,
        DateTime $dateTime,
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        Api $api
    ) {
        $this->eventManager = $eventManager;
        $this->helper = $helper;
        $this->date = $date;
        $this->config = $config;
        $this->customerSession = $customerSession;
        $this->dateTime = $dateTime;
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->api = $api;
    }

    protected function getPaymentMethod(Order $order): array
    {
        return [
            'payment_method_id' => $this->helper->getMethodId('PIX'),
            'split' => 1
        ];
    }

    protected function getTransaction(Order $order, float $amount): array
    {
        $transaction = [
            'token_account'       => $this->helper->getToken($order->getStoreId()),
            'finger_print'        => $order->getPayment()->getAdditionalInformation('finger_print'),
            'customer'            => $this->getCustomerData($order),
            'transaction'         => $this->getTransactionInfo($order, $amount),
            'transaction_product' => $this->getItemsData($order, $amount)
        ];

        $resellerToken = $this->helper->getResellerToken($order->getStoreId());
        if ($resellerToken) {
            $transaction['reseller_token'] = $resellerToken;
        }

        return $transaction;
    }

    /**
     * Get the transaction information.
     *
     * @param Order $order
     * @param float $orderAmount
     * @return array
     */
    protected function getTransactionInfo(Order $order, float $orderAmount): array
    {
        $shippingData = $this->getShippingData($order, $orderAmount);
        
        return [
            'customer_ip'      => $order->getRemoteIp(),
            'order_number'     => $order->getIncrementId(),
            'shipping_type'    => $shippingData['shipping_type'],
            'shipping_price'   => $shippingData['shipping_price'],
            'price_discount'   => (string) $this->getDiscountAmount($order, $orderAmount),
            'price_additional' => (string) $this->getPriceAdditional($order, $orderAmount),
            'url_notification' => $this->helper->getPaymentsNotificationUrl($order),
            'free'             => 'MAGENTO_API_' . $this->helper->getModuleVersion()
        ];
    }

    /**
     * Get the shipping information for the transaction.
     *
     * @param Order $order
     * @param float $transactionAmount
     * @return array
     */
    protected function getShippingData(Order $order, float $transactionAmount = null): array
    {
        $shippingDescription = $order->getShippingDescription();
        $shippingType = $shippingDescription ? $shippingDescription : 'SEM_FRETE';
        
        $shippingAmount = $order->getShippingAmount();
        if ($transactionAmount !== null && $order->getGrandTotal() > 0) {
            $proportion = $transactionAmount / $order->getGrandTotal();
            $shippingAmount = round($shippingAmount * $proportion, 2);
        }

        return [
            'shipping_type' => $shippingType,
            'shipping_price'=> (string) $shippingAmount
        ];
    }

    /**
     * Get the discount amount for the order.
     *
     * @param Order $order
     * @param float $orderAmount
     * @return float
     */
    public function getDiscountAmount(Order $order, $orderAmount): float
    {
        $originalDiscountAmount = abs((float) $order->getDiscountAmount());
        
        if ($originalDiscountAmount <= 0) {
            return 0.0;
        }
        
        if ($orderAmount >= $order->getGrandTotal()) {
            return round($originalDiscountAmount, 2);
        }
        
        $totalOrder = $order->getGrandTotal();
        if ($totalOrder > 0) {
            $proportion = $orderAmount / $totalOrder;
            $proportionalDiscount = $originalDiscountAmount * $proportion;
            
            $shippingAmount = (float) $order->getShippingAmount();
            $proportionalShipping = $shippingAmount * $proportion;
            $maxDiscount = $orderAmount - $proportionalShipping;
            
            if ($proportionalDiscount > $maxDiscount && $maxDiscount > 0) {
                $proportionalDiscount = $maxDiscount;
            }
            
            error_log("DISCOUNT DEBUG - Order: {$order->getIncrementId()}, Original: {$originalDiscountAmount}, OrderAmount: {$orderAmount}, Total: {$totalOrder}, Proportion: {$proportion}, ProportionalDiscount: {$proportionalDiscount}, MaxDiscount: {$maxDiscount}");
            
            return round(max(0, $proportionalDiscount), 2);
        }
        
        return 0.0;
    }

    /**
     * Get the additional price for the transaction.
     *
     * @param Order $order
     * @param float $orderAmount
     * @return float
     */
    protected function getPriceAdditional(Order $order, float $orderAmount): float
    {
        
        $baseSubtotal = (float) $order->getBaseSubtotal();
        $shippingAmount = (float) $order->getShippingAmount();
        $discountAmount = abs((float) $order->getDiscountAmount());
        
        if ($orderAmount < $order->getGrandTotal()) {
            $proportion = $orderAmount / $order->getGrandTotal();
            $baseSubtotal = $baseSubtotal * $proportion;
            $shippingAmount = $shippingAmount * $proportion;
            $discountAmount = $discountAmount * $proportion;
        }
        
        $expectedTotal = $baseSubtotal + $shippingAmount - $discountAmount;
        
        if ($orderAmount > $expectedTotal) {
            $priceAdditional = $orderAmount - $expectedTotal;
            return round($priceAdditional, 2);
        }
        
        return 0.0;
    }

    /**
     * Get customer data.
     *
     * @param Order $order
     * @return array
     */
    public function getCustomerData(Order $order): array
    {
        $address = $order->getBillingAddress();
        $customerTaxVat = $address->getVatId() ?: $order->getCustomerTaxvat();
        $vindiCustomerTaxVat = $order->getPayment()->getAdditionalInformation('vindi_customer_taxvat');
        if ($vindiCustomerTaxVat) {
            $customerTaxVat = $vindiCustomerTaxVat;
        }

        $firstName = $address->getFirstname() ?: $order->getCustomerFirstname();
        $lastName = $address->getLastname() ?: $order->getCustomerLastname();
        $fullName = $order->getCustomerName() ?: $firstName . ' ' . $lastName;

        $customerData = [
            'name'     => $fullName,
            'cpf'      => preg_replace('/\D/', '', (string) $customerTaxVat),
            'email'    => $order->getCustomerEmail(),
            'contacts' => [
                [
                    'type_contact'  => 'M',
                    'number_contact'=> $this->helper->formatPhoneNumber($address->getTelephone())
                ]
            ],
            'addresses' => $this->getAddresses($order)
        ];

        $customerData = $this->helper->getCompanyData($order, $customerData);

        if ($order->getCustomerDob()) {
            $customerData['birth_date'] = $this->helper->formatDate($order->getCustomerDob());
        }

        return $customerData;
    }

    /**
     * Get addresses data.
     *
     * @param Order $order
     * @return array
     */
    protected function getAddresses($order): array
    {
        $billingAddress = $order->getBillingAddress();
        $addresses = [
            [
                'type_address' => 'B',
                'postal_code'  => $billingAddress->getPostcode(),
                'street'       => $billingAddress->getStreetLine($this->getStreetField('street')),
                'number'       => $billingAddress->getStreetLine($this->getStreetField('number')),
                'completion'   => $billingAddress->getStreetLine($this->getStreetField('complement')),
                'neighborhood' => $billingAddress->getStreetLine($this->getStreetField('district')),
                'city'         => $billingAddress->getCity(),
                'state'        => $billingAddress->getRegionCode()
            ]
        ];

        if ($order->getShippingAddress()) {
            $shippingAddress = $order->getShippingAddress();
            $addresses[] = [
                'type_address' => 'D',
                'postal_code'  => $shippingAddress->getPostcode(),
                'street'       => $shippingAddress->getStreetLine($this->getStreetField('street')),
                'number'       => $shippingAddress->getStreetLine($this->getStreetField('number')),
                'completion'   => $shippingAddress->getStreetLine($this->getStreetField('complement')),
                'neighborhood' => $shippingAddress->getStreetLine($this->getStreetField('district')),
                'city'         => $shippingAddress->getCity(),
                'state'        => $shippingAddress->getRegionCode()
            ];
        }

        return $addresses;
    }

    /**
     * Get the street field position.
     *
     * @param string $config
     * @return int
     */
    public function getStreetField(string $config): int
    {
        return (int) $this->helper->getConfig($config, 'address', 'vindi_vp') + 1;
    }

    /**
     * Get items data for the transaction.
     *
     * @param Order $order
     * @param float $transactionAmount
     * @return array
     */
    protected function getItemsData(Order $order, float $transactionAmount = null): array
    {
        $items = [];
        $quoteItems = $order->getAllItems();
        
        $proportion = 1.0;
        if ($transactionAmount !== null && $order->getBaseSubtotal() > 0) {
            $proportion = $transactionAmount / $order->getBaseSubtotal();
        }

        /** @var OrderItemInterface $quoteItem */
        foreach ($quoteItems as $quoteItem) {
            if ($quoteItem->getParentItemId() || $quoteItem->getParentItem() || $quoteItem->getPrice() == 0) {
                continue;
            }

            $item = [];
            $item['description'] = $quoteItem->getName();
            $item['quantity']    = (string) $quoteItem->getQtyOrdered();
            
            $priceUnit = $quoteItem->getPrice();
            if ($transactionAmount !== null) {
                $priceUnit = round($priceUnit * $proportion, 2);
            }
            
            $item['price_unit']  = (string) $priceUnit;
            $item['code']        = $quoteItem->getProductId();
            $item['sku_code']    = $quoteItem->getSku();
            $item['extra']       = $quoteItem->getItemId();

            $this->eventManager->dispatch('vindi_payment_get_item', ['item' => &$item, 'quote_item' => $quoteItem]);

            $items[] = $item;
        }

        return $items;
    }
}
