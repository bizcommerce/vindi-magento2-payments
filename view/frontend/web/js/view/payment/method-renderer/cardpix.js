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
            template: 'Vindi_VP/payment/form/cardpix',
            paymentProfiles: [],
            creditCardType: '',
            creditCardExpDate: '',
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

            // add these so Knockout sees them before bindings fire
            creditAmountManual: ko.observable(),
            pixAmountManual: ko.observable(),
            creditAmountDisplay: ko.observable(),
            pixAmountDisplay: ko.observable(),
            selectedManualMethod: ko.observable(),
            isInstallmentsDisabled: ko.observable(false),

            taxvat: taxvat
        },

        /** Initialize component */
        initialize: function () {
            this._super();
            var config = window.checkoutConfig.payment[this.getCode()] || {};
            this.taxvat.value(config.customer_taxvat || '');
            this.updateInstallments();
            return this;
        },

        /** Initialize observables and computed properties */
        initObservable: function () {
            var self = this;
            this._super()
                .observe([
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

            setCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
            });
            cancelCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
            });

            self.isInstallmentsDisabled = ko.observable(false);
            self.creditAmountManual   = ko.observable();
            self.pixAmountManual      = ko.observable();
            self.selectedManualMethod = ko.observable();
            self.orderTotal           = parseFloat(totals.getSegment('grand_total').value) || 0;
            self.formattedOrderTotal  = ko.computed(function () {
                return priceUtils.formatPrice(self.orderTotal, quote.getPriceFormat());
            });

            self.creditAmountDisplay = ko.computed({
                read: function () {
                    if (self.selectedManualMethod() === 'pix') {
                        var pix = parseFloat(self.pixAmountManual() || 0);
                        return (self.orderTotal - pix).toFixed(2);
                    }
                    return self.creditAmountManual();
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
                    if (self.selectedManualMethod() === 'credit') {
                        var credit = parseFloat(self.creditAmountManual() || 0);
                        return (self.orderTotal - credit).toFixed(2);
                    }
                    return self.pixAmountManual();
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
                return parseFloat(self.creditAmountDisplay() || 0) > self.orderTotal;
            });
            self.pixInvalid = ko.computed(function () {
                return parseFloat(self.pixAmountDisplay() || 0) > self.orderTotal;
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

            return this;
        },

        /** Get payment data */
        getData: function () {
            var expMonth = '', expYear = '',
                parts = (this.creditCardExpDate() || '').split('/');
            if (parts.length === 2) {
                expMonth = parts[0];
                expYear  = parts[1];
            }
            this.creditCardExpYear(expYear);
            this.creditCardExpMonth(expMonth);

            return {
                method: this.getCode(),
                additional_data: {
                    payment_profile:   this.selectedPaymentProfile(),
                    cc_type:           this.selectedCardType(),
                    cc_exp_month:      expMonth,
                    cc_exp_year:       expYear.length === 4 ? expYear : '20' + expYear,
                    cc_number:         this.creditCardNumber(),
                    cc_owner:          this.creditCardOwner(),
                    cc_ss_start_month: this.creditCardSsStartMonth(),
                    cc_ss_start_year:  this.creditCardSsStartYear(),
                    cc_cvv:            this.creditCardVerificationNumber(),
                    cc_installments:   this.selectedInstallments() || 1,
                    document:          this.taxvat.value(),
                    amount_credit:     this.creditAmountDisplay(),
                    amount_pix:        this.pixAmountDisplay()
                }
            };
        },

        /** Validate payment fields */
        validate: function () {
            var self = this;
            if (!this.selectedPaymentProfile()) {
                this.messageContainer.addErrorMessage({ message: $t('Please select a payment profile.') });
                return false;
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
            var credit = parseFloat(self.creditAmountDisplay() || 0),
                pix    = parseFloat(self.pixAmountDisplay()   || 0);
            if (credit + pix !== self.orderTotal) {
                this.messageContainer.addErrorMessage({ message: $t('Sum of Credit and PIX must equal order total.') });
                return false;
            }
            return true;
        },

        /**
         * Determine if installments feature is enabled in config
         *
         * @return {Boolean}
         */
        installmentsAllowed: function () {
            var cfg = window.checkoutConfig.payment[this.getCode()] || {};
            return parseInt(cfg.isInstallmentsAllowedInStore || 0, 10) !== 0;
        },

        /**
         * Update installments options based on config and current amounts
         *
         * @param {Number|null} max
         */
        updateInstallments: function (max) {
            var self    = this,
                cfg     = window.checkoutConfig.payment[this.getCode()] || {},
                allowed = parseInt(cfg.isInstallmentsAllowedInStore || 0, 10) !== 0,
                total   = self.selectedManualMethod() === 'pix'
                    ? parseFloat(self.pixAmountManual() || 0)
                    : parseFloat(self.creditAmountManual() || 0),
                arr     = [];

            self.isInstallmentsDisabled(true);

            if (!allowed) {
                self.selectedInstallments(1);
                arr.push({
                    value: 1,
                    text: '1 x ' + priceUtils.formatPrice(total, quote.getPriceFormat())
                });
            } else {
                var maxInst = max || parseInt(cfg.maxInstallments, 10) || 1,
                    minVal  = cfg.minInstallmentsValue;
                for (var i = 1; i <= maxInst; i++) {
                    if (i > 1 && i * minVal > total) {
                        break;
                    }
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
                self.selectedInstallments(arr[0].value);
            }

            self.creditCardInstallments(arr);
            self.isInstallmentsDisabled(false);
        },

        /** Load card form UI */
        loadCard: function () {
            creditCardForm(
                document.getElementById(this.getCode() + '_cc_owner'),
                document.getElementById(this.getCode() + '_cc_number'),
                document.getElementById(this.getCode() + '_cc_exp_date'),
                document.getElementById(this.getCode() + '_cc_cid')
            );
        },

        /** Format CPF/CNPJ on input */
        checkCpf: function (_, event) {
            this.taxvat.formatDocument(event.target);
        }
    });
});
