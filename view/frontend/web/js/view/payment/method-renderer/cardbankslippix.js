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
            template: 'Vindi_VP/payment/form/cardbankslippix',
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

            bankslippixAmountManual: ko.observable(),
            creditAmountManual: ko.observable(),
            selectedManualMethod: ko.observable(),
            isInstallmentsDisabled: ko.observable(false),
            taxvat: taxvat
        },

        /** Initialize observables */
        initObservable: function () {
            var self = this;
            this._super()
                .observe([
                    'creditCardExpDate','creditCardExpYear','creditCardExpMonth','creditCardNumber',
                    'vindiCreditCardNumber','creditCardOwner','creditCardVerificationNumber',
                    'selectedCardType','selectedPaymentProfile','selectedInstallments','maxInstallments'
                ]);

            setCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
            });
            cancelCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
            });

            this.checkPlanInstallments();

            return this;
        },

        /** Get payment data */
        getData: function () {
            var parts = (this.creditCardExpDate() || '').split('/'),
                month = parts[0] || '',
                year  = parts[1] || '';

            return {
                method: this.getCode(),
                additional_data: {
                    cc_owner: this.creditCardOwner(),
                    cc_number: this.creditCardNumber(),
                    cc_exp_month: month,
                    cc_exp_year: year.length === 4 ? year : '20' + year,
                    cc_cvv: this.creditCardVerificationNumber(),
                    cc_installments: this.selectedInstallments() || 1,

                    document: this.taxvat.value(),
                    amount: this.selectedManualMethod() === 'bankslippix'
                        ? this.bankslippixAmountManual()
                        : this.creditAmountManual()
                }
            };
        },

        /** Validate payment fields */
        validate: function () {
            var doc = this.taxvat.value();
            if (!doc) {
                this.messageContainer.addErrorMessage({ message: $t('CPF/CNPJ is required') });
                return false;
            }
            if (!documentValidate.isValidTaxvat(doc)) {
                this.messageContainer.addErrorMessage({ message: $t('Invalid CPF/CNPJ') });
                return false;
            }
            return true;
        },

        /**
         * Check if installments are allowed
         *
         * @return {Boolean}
         */
        installmentsAllowed: function () {
            var cfg = window.checkoutConfig.payment[this.getCode()] || {};
            return parseInt(cfg.isInstallmentsAllowedInStore, 10) !== 0;
        },

        /**
         * Update installments options
         *
         * @param {Number} maxInstallments
         */
        updateInstallments: function (maxInstallments) {
            var self  = this,
                cfg   = window.checkoutConfig.payment[this.getCode()] || {},
                total = self.selectedManualMethod() === 'bankslippix'
                    ? parseFloat(self.bankslippixAmountManual() || 0)
                    : parseFloat(self.creditAmountManual()   || 0),
                arr   = [];

            self.isInstallmentsDisabled(true);

            if (!parseInt(cfg.isInstallmentsAllowedInStore, 10)) {
                self.selectedInstallments(1);
            } else {
                var maxInst = maxInstallments || parseInt(cfg.maxInstallments, 10) || 1,
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
                self.creditCardInstallments(arr);
            }

            self.isInstallmentsDisabled(false);
        },

        /**
         * Initialize installments count from config
         */
        checkPlanInstallments: function () {
            var cfg = window.checkoutConfig.payment[this.getCode()] || {},
                max = parseInt(cfg.maxInstallments, 10) || 1;
            this.updateInstallments(max);
        },

        /** Load card UI */
        loadCard: function () {
            creditCardForm(
                document.getElementById(this.getCode() + '_cc_owner'),
                document.getElementById(this.getCode() + '_cc_number'),
                document.getElementById(this.getCode() + '_cc_exp_date'),
                document.getElementById(this.getCode() + '_cc_cid')
            );
        },

        /** Formatter for CPF/CNPJ field */
        checkCpf: function (_, event) {
            this.taxvat.formatDocument(event.target);
        }
    });
});
