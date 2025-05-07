// File: app/code/Vindi/VP/view/frontend/web/js/view/payment/method-renderer/vindi-vp-cardpix.js
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
            template: 'Vindi_VP/payment/vindi-vp-cardpix',
            paymentProfiles: [],
            creditCardType: '',
            creditCardExpYear: '',
            creditCardExpMonth: '',
            creditCardNumber: '',
            vindiCreditCardNumber: '',
            creditCardOwner: '',
            creditCardSsStartMonth: '',
            creditCardSsStartYear: '',
            showCardData: ko.observable(true),
            creditCardVerificationNumber: '',
            selectedPaymentProfile: null,
            selectedCardType: null,
            selectedInstallments: null,
            creditCardInstallments: ko.observableArray([]),
            maxInstallments: 1,
            taxvat: taxvat
        },

        /**
         * Get payment data
         *
         * @return {Object}
         */
        getData: function () {
            var ccExpMonth = '';
            var ccExpYear = '';
            var ccExpDate = this.creditCardExpDate();

            if (ccExpDate) {
                var parts = ccExpDate.split('/');
                ccExpMonth = parts[0];
                ccExpYear = parts[1];
            }

            this.creditCardExpYear(ccExpYear);
            this.creditCardExpMonth(ccExpMonth);

            return {
                method: this.getCode(),
                additional_data: {
                    payment_profile: this.selectedPaymentProfile(),
                    cc_type: this.selectedCardType(),
                    cc_exp_year: ccExpYear.length === 4 ? ccExpYear : '20' + ccExpYear,
                    cc_exp_month: ccExpMonth,
                    cc_number: this.creditCardNumber(),
                    cc_owner: this.creditCardOwner(),
                    cc_ss_start_month: this.creditCardSsStartMonth(),
                    cc_ss_start_year: this.creditCardSsStartYear(),
                    cc_cvv: this.creditCardVerificationNumber(),
                    cc_installments: this.selectedInstallments() || 1,
                    document: this.taxvat.value(),
                    amount_credit: this.creditAmountDisplay(),
                    amount_pix: this.pixAmountDisplay()
                }
            };
        },

        /**
         * Initialize observables and computed properties
         *
         * @return {Component}
         */
        initObservable: function () {
            var self = this;
            this._super()
                .observe([
                    'creditCardType',
                    'creditCardExpDate',
                    'creditCardExpYear',
                    'creditCardExpMonth',
                    'creditCardNumber',
                    'vindiCreditCardNumber',
                    'creditCardOwner',
                    'creditCardVerificationNumber',
                    'creditCardSsStartMonth',
                    'creditCardSsStartYear',
                    'selectedCardType',
                    'selectedPaymentProfile',
                    'selectedInstallments',
                    'maxInstallments'
                ]);

            self.isInstallmentsDisabled = ko.observable(false);

            setCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
            });
            cancelCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
            });

            self.creditAmountManual   = ko.observable();
            self.pixAmountManual      = ko.observable();
            self.selectedManualMethod = ko.observable();
            self.orderTotal           = parseFloat(totals.getSegment('grand_total').value) || 0;
            self.formattedOrderTotal  = ko.computed(function () {
                return priceUtils.formatPrice(self.orderTotal, window.checkoutConfig.payment['vindi_vp_cardpix'].currency);
            });

            self.creditAmountDisplay = ko.computed({
                read: function () {
                    if (self.selectedManualMethod() !== 'pix') {
                        return self.creditAmountManual();
                    }
                    var pix = parseFloat(self.pixAmountManual() || 0);
                    return (self.orderTotal - pix).toFixed(2);
                },
                write: function (value) {
                    if (!value) {
                        self.selectedManualMethod(null);
                        self.creditAmountManual('');
                        self.pixAmountManual('');
                    } else {
                        self.selectedManualMethod('credit');
                        self.creditAmountManual(value);
                    }
                }
            });

            self.pixAmountDisplay = ko.computed({
                read: function () {
                    if (self.selectedManualMethod() !== 'credit') {
                        return self.pixAmountManual();
                    }
                    var credit = parseFloat(self.creditAmountManual() || 0);
                    return (self.orderTotal - credit).toFixed(2);
                },
                write: function (value) {
                    if (!value) {
                        self.selectedManualMethod(null);
                        self.creditAmountManual('');
                        self.pixAmountManual('');
                    } else {
                        self.selectedManualMethod('pix');
                        self.pixAmountManual(value);
                    }
                }
            });

            self.isCreditEditable = ko.computed(function () {
                return self.selectedManualMethod() !== 'pix';
            });
            self.isPixEditable = ko.computed(function () {
                return self.selectedManualMethod() !== 'credit';
            });
            self.creditInvalid = ko.computed(function () {
                return parseFloat(self.creditAmountManual() || 0) > self.orderTotal;
            });
            self.pixInvalid = ko.computed(function () {
                return parseFloat(self.pixAmountManual() || 0) > self.orderTotal;
            });

            self.creditAmountManual.subscribe(function () {
                self.updateInstallments();
            });

            this.vindiCreditCardNumber.subscribe(function (value) {
                var result = cardNumberValidator(value);
                if (result.isValid) {
                    self.selectedCardType(result.card.type);
                    creditCardData.creditCard = result.card;
                    self.creditCardNumber(value);
                    self.creditCardType(result.card.type);
                }
            });

            this.checkPlanInstallments();
            return this;
        },

        /**
         * Validate payment fields
         *
         * @return {Boolean}
         */
        validate: function () {
            var self = this;
            if (!this.selectedPaymentProfile()) {
                if (!this.selectedCardType()) {
                    this.messageContainer.addErrorMessage({ message: $t('Please enter the Credit Card Type.') });
                    return false;
                }
                if (!this.creditCardExpDate()) {
                    this.messageContainer.addErrorMessage({ message: $t('Please enter the Credit Card Expiry Date.') });
                    return false;
                }
                if (!this.creditCardNumber()) {
                    this.messageContainer.addErrorMessage({ message: $t('Please enter the Credit Card Number.') });
                    return false;
                }
                if (!this.creditCardOwner()) {
                    this.messageContainer.addErrorMessage({ message: $t('Please enter the Credit Card Owner Name.') });
                    return false;
                }
                if (!this.creditCardVerificationNumber()) {
                    this.messageContainer.addErrorMessage({ message: $t('Please enter the Credit Card CVV.') });
                    return false;
                }
            }

            var doc = this.taxvat.value();
            if (!doc) {
                this.messageContainer.addErrorMessage({ message: $t('CPF/CNPJ is required') });
                return false;
            }
            if (!documentValidate.isValidTaxvat(doc)) {
                this.messageContainer.addErrorMessage({ message: $t('Invalid CPF/CNPJ') });
                return false;
            }

            if (this.installmentsAllowed() && !this.selectedInstallments()) {
                this.messageContainer.addErrorMessage({ message: $t('Please select number of installments.') });
                return false;
            }

            var credit = parseFloat(self.creditAmountDisplay()  || 0),
                pix    = parseFloat(self.pixAmountDisplay()     || 0);

            if (credit + pix !== self.orderTotal) {
                this.messageContainer.addErrorMessage({ message: $t('Sum of Credit and PIX must equal order total.') });
                return false;
            }
            return true;
        },

        /**
         * Initialize component
         *
         * @return {Component}
         */
        initialize: function () {
            this._super();
            this.taxvat.value(window.checkoutConfig.payment['vindi_vp_cardpix'].customer_taxvat);
            this.updateInstallments();
        },

        /**
         * Get icon for card type
         *
         * @param {String} type
         * @return {Object|Boolean}
         */
        getIcons: function (type) {
            var cfg = window.checkoutConfig.payment['vindi_vp_cardpix'];
            return cfg.icons && cfg.icons[type] ? cfg.icons[type] : false;
        },

        /**
         * Update installments options
         *
         * @param {Number|null} max
         */
        updateInstallments: function (max) {
            var self = this,
                cfg  = window.checkoutConfig.payment['vindi_vp_cardpix'],
                total = self.selectedManualMethod() === 'pix'
                    ? parseFloat(self.pixAmountManual() || 0)
                    : parseFloat(self.creditAmountManual() || 0);
            self.creditCardInstallments([]);
            if (!cfg.isInstallmentsAllowedInStore) {
                self.selectedInstallments(1);
                return;
            }
            var maxInst = max || cfg.maxInstallments,
                minVal  = cfg.minInstallmentsValue,
                arr     = [];
            for (var i = 1; i <= maxInst; i++) {
                if (i * minVal > total && i > 1) break;
                arr.push({ value: i, text: i + ' x ' + priceUtils.formatPrice(total / i, quote.getPriceFormat()) });
            }
            self.creditCardInstallments(arr.length ? arr : [{ value:1, text: '1 x ' + priceUtils.formatPrice(total, quote.getPriceFormat()) }]);
        },

        /**
         * Check AJAX for plan installments
         */
        checkPlanInstallments: function () {
            var self = this;
            $.get(window.BASE_URL + 'vindi_vp/plan/get', function (res) {
                self.updateInstallments(res.installments);
            });
        }
    });
});
