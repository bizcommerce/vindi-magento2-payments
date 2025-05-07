// File: app/code/Vindi/VP/view/frontend/web/js/view/payment/method-renderer/vindi-vp-cardbankslippix.js
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
            template: 'Vindi_VP/payment/vindi-vp-cardbankslippix',
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
            var ccExpYear  = '';
            var ccExpDate  = this.creditCardExpDate();

            if (ccExpDate) {
                var parts = ccExpDate.split('/');
                ccExpMonth = parts[0];
                ccExpYear  = parts[1];
            }

            this.creditCardExpYear(ccExpYear);
            this.creditCardExpMonth(ccExpMonth);

            return {
                method: this.getCode(),
                additional_data: {
                    payment_profile:     this.selectedPaymentProfile(),
                    cc_type:             this.selectedCardType(),
                    cc_exp_year:         ccExpYear.length === 4 ? ccExpYear : '20' + ccExpYear,
                    cc_exp_month:        ccExpMonth,
                    cc_number:           this.creditCardNumber(),
                    cc_owner:            this.creditCardOwner(),
                    cc_ss_start_month:   this.creditCardSsStartMonth(),
                    cc_ss_start_year:    this.creditCardSsStartYear(),
                    cc_cvv:              this.creditCardVerificationNumber(),
                    cc_installments:     this.selectedInstallments() || 1,
                    document:            this.taxvat.value(),
                    amount_credit:       this.creditAmountDisplay(),
                    amount_bankslippix:  this.bankslippixAmountDisplay()
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
                    'creditCardType','creditCardExpDate','creditCardExpYear','creditCardExpMonth',
                    'creditCardNumber','vindiCreditCardNumber','creditCardOwner',
                    'creditCardVerificationNumber','creditCardSsStartMonth','creditCardSsStartYear',
                    'selectedCardType','selectedPaymentProfile','selectedInstallments','maxInstallments'
                ]);

            self.isInstallmentsDisabled      = ko.observable(false);
            self.creditAmountManual          = ko.observable();
            self.bankslippixAmountManual     = ko.observable();
            self.selectedManualMethod        = ko.observable();
            self.orderTotal                  = parseFloat(totals.getSegment('grand_total').value) || 0;
            self.formattedOrderTotal         = ko.computed(function() {
                return priceUtils.formatPrice(self.orderTotal, quote.getPriceFormat());
            });

            setCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
            });
            cancelCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
            });

            self.creditAmountDisplay = ko.computed({
                read: function() {
                    if (self.selectedManualMethod() !== 'bankslippix') {
                        return self.creditAmountManual();
                    }
                    var b = parseFloat(self.bankslippixAmountManual() || 0);
                    return (self.orderTotal - b).toFixed(2);
                },
                write: function(value) {
                    if (!value) {
                        self.selectedManualMethod(null);
                        self.creditAmountManual('');
                        self.bankslippixAmountManual('');
                    } else {
                        self.selectedManualMethod('credit');
                        self.creditAmountManual(value);
                    }
                }
            });

            self.bankslippixAmountDisplay = ko.computed({
                read: function() {
                    if (self.selectedManualMethod() === 'bankslippix') {
                        return self.bankslippixAmountManual();
                    }
                    var c = parseFloat(self.creditAmountManual() || 0);
                    return (self.orderTotal - c).toFixed(2);
                },
                write: function(value) {
                    if (!value) {
                        self.selectedManualMethod(null);
                        self.creditAmountManual('');
                        self.bankslippixAmountManual('');
                    } else {
                        self.selectedManualMethod('bankslippix');
                        self.bankslippixAmountManual(value);
                    }
                }
            });

            self.isCreditEditable      = ko.computed(function() {
                return self.selectedManualMethod() !== 'bankslippix';
            });
            self.isBankslippixEditable = ko.computed(function() {
                return self.selectedManualMethod() === 'bankslippix' || !self.selectedManualMethod();
            });

            self.creditInvalid      = ko.computed(function() {
                return parseFloat(self.creditAmountManual() || 0) > self.orderTotal;
            });
            self.bankslippixInvalid = ko.computed(function() {
                return parseFloat(self.bankslippixAmountManual() || 0) > self.orderTotal;
            });

            this.vindiCreditCardNumber.subscribe(function(value) {
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

            var c = parseFloat(self.creditAmountDisplay()  || 0),
                b = parseFloat(self.bankslippixAmountDisplay() || 0);

            if (c + b !== self.orderTotal) {
                this.messageContainer.addErrorMessage({ message: $t('Sum of Credit and Bolepix must equal order total.') });
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
            this.taxvat.value(window.checkoutConfig.payment['vindi_vp_cardbankslippix'].customer_taxvat);
            this.updateInstallments();
        },

        /**
         * Load the credit card form
         */
        loadCard: function () {
            var ccName  = document.getElementById(this.getCode() + '_cc_owner'),
                ccNumber= document.getElementById(this.getCode() + '_cc_number'),
                ccExp   = document.getElementById(this.getCode() + '_cc_exp_date'),
                ccCvv   = document.getElementById(this.getCode() + '_cc_cid'),
                ccSingle= document.getElementById('vindi-ccsingle'),
                ccFront = document.getElementById('vindi-front'),
                ccBack  = document.getElementById('vindi-back');

            creditCardForm(ccName, ccNumber, ccExp, ccCvv, ccSingle, ccFront, ccBack);
        },

        /**
         * Check if component is active
         *
         * @return {Boolean}
         */
        isActive: function () {
            return true;
        },

        /**
         * Get available credit card types
         *
         * @return {Object}
         */
        getCcAvailableTypes: function () {
            return window.checkoutConfig.payment['vindi_vp_cardbankslippix'].availableTypes;
        },

        /**
         * Get credit card months
         *
         * @return {Object}
         */
        getCcMonths: function () {
            return window.checkoutConfig.payment['vindi_vp_cardbankslippix'].months['vindi_cardbankslippix'];
        },

        /**
         * Get credit card years
         *
         * @return {Object}
         */
        getCcYears: function () {
            return window.checkoutConfig.payment['vindi_vp_cardbankslippix'].years['vindi_cardbankslippix'];
        },

        /**
         * Check if credit card verification is required
         *
         * @return {Boolean}
         */
        hasVerification: function () {
            return window.checkoutConfig.payment['vindi_vp_cardbankslippix'].hasVerification['vindi_cardbankslippix'];
        },

        /**
         * Get available credit card types values
         *
         * @return {Array}
         */
        getCcAvailableTypesValues: function () {
            return _.map(this.getCcAvailableTypes(), function (value, key) {
                return { value: key, type: value };
            });
        },

        /**
         * Get credit card months values
         *
         * @return {Array}
         */
        getCcMonthsValues: function () {
            return _.map(this.getCcMonths(), function (value, key) {
                return { value: key, month: value };
            });
        },

        /**
         * Get credit card years values
         *
         * @return {Array}
         */
        getCcYearsValues: function () {
            return _.map(this.getCcYears(), function (value, key) {
                return { value: key, year: value };
            });
        },

        /**
         * Get credit card types values
         *
         * @return {Array}
         */
        getCcTypesValues: function () {
            return _.map(this.getCcAvailableTypes(), function (value, key) {
                return { value: key, name: value };
            });
        },

        /**
         * Check if installments are allowed
         *
         * @return {Boolean}
         */
        installmentsAllowed: function () {
            var cfg = window.checkoutConfig.payment['vindi_vp_cardbankslippix'];
            return parseInt(cfg.isInstallmentsAllowedInStore) !== 0;
        },

        /**
         * Update installments options
         *
         * @param {Number|null} maxInstallments
         */
        updateInstallments: function (maxInstallments) {
            var self = this,
                cfg  = window.checkoutConfig.payment['vindi_vp_cardbankslippix'],
                total = self.selectedManualMethod() === 'bankslippix'
                    ? parseFloat(self.bankslippixAmountManual() || 0)
                    : parseFloat(self.creditAmountManual()   || 0),
                arr   = [];

            self.isInstallmentsDisabled(true);

            if (!cfg.isInstallmentsAllowedInStore) {
                self.selectedInstallments(1);
            } else {
                var maxInst = maxInstallments || cfg.maxInstallments,
                    minVal  = cfg.minInstallmentsValue;
                for (var i = 1; i <= maxInst; i++) {
                    if (i * minVal > total && i > 1) break;
                    arr.push({
                        value: i,
                        text: i + ' x ' + priceUtils.formatPrice(total / i, quote.getPriceFormat())
                    });
                }
                if (arr.length === 0) {
                    arr.push({
                        value: 1,
                        text: '1 x ' + priceUtils.formatPrice(total, quote.getPriceFormat())
                    });
                }
                self.creditCardInstallments(arr);
            }

            self.isInstallmentsDisabled(false);
        },

        /**
         * Check plan installments via AJAX
         */
        checkPlanInstallments: function () {
            var self = this;
            $.get(window.BASE_URL + 'vindi_vp/plan/get', function (res) {
                self.updateInstallments(res.installments);
            });
        },

        /**
         * Get informational message for Bolepix payment
         *
         * @return {String}
         */
        getInfoMessage: function () {
            return window.checkoutConfig.payment['vindi_vp_cardbankslippix'].info_message;
        },

        /**
         * Check if document field is active
         *
         * @return {Boolean}
         */
        isActiveDocument: function () {
            return window.checkoutConfig.payment['vindi_vp_cardbankslippix'].enabledDocument;
        },

        /**
         * Check CPF input field on keyup event
         *
         * @param {Object} self
         * @param {Event} event
         */
        checkCpf: function (self, event) {
            this.formatTaxvat(event.target);
            var msg = documentValidate.isValidTaxvat(this.taxvat.value()) ? '' : 'CPF/CNPJ inválido';
            $('#cpfResponse').text(msg);
        },

        /**
         * Format document field using taxvat model
         *
         * @param {HTMLElement} target
         */
        formatTaxvat: function (target) {
            taxvat.formatDocument(target);
        },

        /**
         * Get URL for given path
         *
         * @param {String} path
         * @return {String}
         */
        getUrl: function (path) {
            return window.BASE_URL + path;
        }
    });
});
