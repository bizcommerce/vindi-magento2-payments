define([
    'underscore',
    'ko',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Payment/js/model/credit-card-validation/credit-card-data',
    'Vindi_VP/js/model/credit-card-validation/credit-card-number-validator',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/totals',
    'Magento_SalesRule/js/action/set-coupon-code',
    'Magento_SalesRule/js/action/cancel-coupon',
    'Magento_Catalog/js/price-utils',
    'mage/translate',
    'jquery',
    'vindi-card-form',
    'mageUtils',
    'Vindi_VP/js/model/taxvat',
    'Vindi_VP/js/model/validate'
], function (
    _,
    ko,
    Component,
    creditCardData,
    cardNumberValidator,
    quote,
    totals,
    setCouponCodeAction,
    cancelCouponCodeAction,
    priceUtils,
    $t,
    $,
    creditCardForm,
    utils,
    taxvat,
    documentValidate
) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Vindi_VP/payment/form/cardcard',
            paymentProfiles: [],

            creditCardType: '',
            creditCardExpDate: '',
            creditCardExpYear: '',
            creditCardExpMonth: '',
            creditCardNumber: '',
            vindiCreditCardNumber: '',
            creditCardOwner: '',
            creditCardVerificationNumber: '',
            selectedPaymentProfile: null,
            selectedCardType: null,
            selectedInstallments: null,
            creditCardInstallments: ko.observableArray([]),
            maxInstallments: 1,

            creditCardType2: '',
            creditCardExpDate2: '',
            creditCardExpYear2: '',
            creditCardExpMonth2: '',
            creditCardNumber2: '',
            vindiCreditCardNumber2: '',
            creditCardOwner2: '',
            creditCardVerificationNumber2: '',
            selectedPaymentProfile2: null,
            selectedCardType2: null,
            selectedInstallments2: null,
            creditCardInstallments2: ko.observableArray([]),
            maxInstallments2: 1,

            creditAmountManual: ko.observable(),
            secondCardAmountManual: ko.observable(),
            selectedManualMethod: ko.observable(),

            // these two must exist before KO bindings
            creditAmountDisplay: ko.observable(),
            secondCardAmountDisplay: ko.observable(),

            isInstallmentsDisabled: ko.observable(false),
            isInstallmentsDisabled2: ko.observable(false),

            // placeholders for editability and validation
            isFirstCardEditable: ko.observable(true),
            isSecondCardEditable: ko.observable(true),
            firstCardInvalid: ko.observable(false),
            secondCardInvalid: ko.observable(false),

            taxvat: taxvat
        },

        /** Initialize component */
        initialize: function () {
            this._super();
            var config = window.checkoutConfig.payment[this.getCode()] || {};
            this.taxvat.value(config.customer_taxvat || '');
            this.updateInstallments();
            this.updateInstallments2();
            return this;
        },

        /** Initialize observables and computed properties */
        initObservable: function () {
            var self = this;
            this._super()
                .observe([
                    'creditCardExpDate','creditCardExpYear','creditCardExpMonth','creditCardNumber',
                    'vindiCreditCardNumber','creditCardOwner','creditCardVerificationNumber',
                    'selectedCardType','selectedPaymentProfile','selectedInstallments','maxInstallments',
                    'creditCardExpDate2','creditCardExpYear2','creditCardExpMonth2','creditCardNumber2',
                    'vindiCreditCardNumber2','creditCardOwner2','creditCardVerificationNumber2',
                    'selectedCardType2','selectedPaymentProfile2','selectedInstallments2','maxInstallments2'
                ]);

            // update installments when coupons apply or change
            setCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
                self.updateInstallments2();
            });
            cancelCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
                self.updateInstallments2();
            });

            self.orderTotal = parseFloat(totals.getSegment('grand_total').value) || 0;

            // computed display amount for first card
            self.creditAmountDisplay = ko.computed({
                read: function() {
                    if (self.selectedManualMethod() === 'secondCard') {
                        var sec = parseFloat(self.secondCardAmountManual() || 0);
                        return (self.orderTotal - sec).toFixed(2);
                    }
                    return self.creditAmountManual();
                },
                write: function(val) {
                    if (!val) {
                        self.selectedManualMethod(null);
                        self.creditAmountManual('');
                        self.secondCardAmountManual('');
                    } else {
                        self.selectedManualMethod('firstCard');
                        self.creditAmountManual(val);
                    }
                }
            });

            // computed display amount for second card
            self.secondCardAmountDisplay = ko.computed({
                read: function() {
                    if (self.selectedManualMethod() === 'firstCard') {
                        var fst = parseFloat(self.creditAmountManual() || 0);
                        return (self.orderTotal - fst).toFixed(2);
                    }
                    return self.secondCardAmountManual();
                },
                write: function(val) {
                    if (!val) {
                        self.selectedManualMethod(null);
                        self.creditAmountManual('');
                        self.secondCardAmountManual('');
                    } else {
                        self.selectedManualMethod('secondCard');
                        self.secondCardAmountManual(val);
                    }
                }
            });

            // recompute installments when manual amounts change
            self.creditAmountManual.subscribe(function() {
                self.updateInstallments();
            });
            self.secondCardAmountManual.subscribe(function() {
                self.updateInstallments2();
            });

            // computed editability flags
            self.isFirstCardEditable = ko.computed(function() {
                return self.selectedManualMethod() !== 'secondCard';
            });
            self.isSecondCardEditable = ko.computed(function() {
                return self.selectedManualMethod() !== 'firstCard';
            });

            // computed invalid flags for border styling
            self.firstCardInvalid = ko.computed(function() {
                return parseFloat(self.creditAmountManual() || 0) > self.orderTotal;
            });
            self.secondCardInvalid = ko.computed(function() {
                return parseFloat(self.secondCardAmountManual() || 0) > self.orderTotal;
            });

            // card-number subscriptions for type detection
            this.vindiCreditCardNumber.subscribe(function(value) {
                var r = cardNumberValidator(value);
                if (r.isValid) {
                    self.selectedCardType(r.card.type);
                    creditCardData.creditCard = r.card;
                    self.creditCardNumber(value);
                    self.creditCardType(r.card.type);
                }
            });
            this.vindiCreditCardNumber2.subscribe(function(value) {
                var r = cardNumberValidator(value);
                if (r.isValid) {
                    self.selectedCardType2(r.card.type);
                    self.creditCardNumber2(value);
                    self.creditCardType2(r.card.type);
                }
            });

            // initial installments
            this.checkPlanInstallments();

            return this;
        },

        /** Get payment data */
        getData: function () {
            var parts1 = (this.creditCardExpDate() || '').split('/'),
                m1 = parts1[0] || '', y1 = parts1[1] || '',
                parts2 = (this.creditCardExpDate2() || '').split('/'),
                m2 = parts2[0] || '', y2 = parts2[1] || '';

            this.creditCardExpYear(y1);
            this.creditCardExpMonth(m1);
            this.creditCardExpYear2(y2);
            this.creditCardExpMonth2(m2);

            return {
                method: this.getCode(),
                additional_data: {
                    payment_profile1:   this.selectedPaymentProfile(),
                    cc_type1:           this.selectedCardType(),
                    cc_exp_year1:       y1.length === 4 ? y1 : (y1 ? '20'+y1 : ''),
                    cc_exp_month1:      m1,
                    cc_number1:         this.creditCardNumber(),
                    cc_owner1:          this.creditCardOwner(),
                    cc_cvv1:            this.creditCardVerificationNumber(),
                    cc_installments1:   this.selectedInstallments() || 1,

                    payment_profile2:   this.selectedPaymentProfile2(),
                    cc_type2:           this.selectedCardType2(),
                    cc_exp_year2:       y2.length === 4 ? y2 : (y2 ? '20'+y2 : ''),
                    cc_exp_month2:      m2,
                    cc_number2:         this.creditCardNumber2(),
                    cc_owner2:          this.creditCardOwner2(),
                    cc_cvv2:            this.creditCardVerificationNumber2(),
                    cc_installments2:   this.selectedInstallments2() || 1,

                    document:           this.taxvat.value(),
                    amount_first_card:  this.creditAmountDisplay(),
                    amount_second_card: this.secondCardAmountDisplay()
                }
            };
        },

        /** Validate inputs */
        validate: function () {
            var first  = parseFloat(this.creditAmountDisplay() || 0),
                second = parseFloat(this.secondCardAmountDisplay() || 0);

            if (!this.taxvat.value()) {
                this.messageContainer.addErrorMessage({ message: $t('CPF/CNPJ is required') });
                return false;
            }
            if (!documentValidate.isValidTaxvat(this.taxvat.value())) {
                this.messageContainer.addErrorMessage({ message: $t('Invalid CPF/CNPJ') });
                return false;
            }
            if ((first + second).toFixed(2) !== parseFloat(totals.getSegment('grand_total').value).toFixed(2)) {
                this.messageContainer.addErrorMessage({ message: $t('Sum of both cards must equal order total') });
                return false;
            }
            return true;
        },

        /** Update first-card installments */
        updateInstallments: function(max) {
            var cfg   = window.checkoutConfig.payment[this.getCode()] || {},
                total = parseFloat(this.creditAmountDisplay() || 0),
                arr   = [];

            this.isInstallmentsDisabled(true);
            if (parseInt(cfg.isInstallmentsAllowedInStore || 0, 10) !== 0) {
                var maxI = max || parseInt(cfg.maxInstallments, 10) || 1,
                    minV = cfg.minInstallmentsValue;
                for (var i = 1; i <= maxI; i++) {
                    var v = Math.ceil((total / i) * 100) / 100;
                    if (i > 1 && i * minV > total) break;
                    arr.push({
                        value: i,
                        text: i + ' x ' + priceUtils.formatPrice(total / i, quote.getPriceFormat())
                    });
                }
            }
            this.creditCardInstallments(arr.length ? arr : [{
                value: 1,
                text: '1 x ' + priceUtils.formatPrice(total, quote.getPriceFormat())
            }]);
            this.isInstallmentsDisabled(false);
        },

        /** Update second-card installments */
        updateInstallments2: function(max) {
            var cfg   = window.checkoutConfig.payment[this.getCode()] || {},
                total = parseFloat(this.secondCardAmountDisplay() || 0),
                arr   = [];

            this.isInstallmentsDisabled2(true);
            if (parseInt(cfg.isInstallmentsAllowedInStore || 0, 10) !== 0) {
                var maxI = max || parseInt(cfg.maxInstallments, 10) || 1,
                    minV = cfg.minInstallmentsValue;
                for (var i = 1; i <= maxI; i++) {
                    var v = Math.ceil((total / i) * 100) / 100;
                    if (i > 1 && i * minV > total) break;
                    arr.push({
                        value: i,
                        text: i + ' x ' + priceUtils.formatPrice(total / i, quote.getPriceFormat())
                    });
                }
            }
            this.creditCardInstallments2(arr.length ? arr : [{
                value: 1,
                text: '1 x ' + priceUtils.formatPrice(total, quote.getPriceFormat())
            }]);
            this.isInstallmentsDisabled2(false);
        },

        /**
         * Initialize installments counts from config
         *
         * @return {void}
         */
        checkPlanInstallments: function () {
            var cfg = window.checkoutConfig.payment[this.getCode()] || {},
                max = parseInt(cfg.maxInstallments, 10) || 1;
            this.updateInstallments(max);
            this.updateInstallments2(max);
        }
    });
});
