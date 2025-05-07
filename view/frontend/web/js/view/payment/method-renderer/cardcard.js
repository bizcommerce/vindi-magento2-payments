// File: app/code/Vindi/VP/view/frontend/web/js/view/payment/method-renderer/vindi-vp-cardcard.js
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
            template: 'Vindi_VP/payment/vindi-vp-cardcard',
            // First card observables
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
            // Second card observables
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
            // Shared
            taxvat: taxvat
        },

        /**
         * Get payment data
         * @return {Object}
         */
        getData: function () {
            var ccExpMonth = '', ccExpYear = '',
                ccExp = this.creditCardExpDate() || '',
                parts = ccExp.split('/');
            if (parts.length >= 2) {
                ccExpMonth = parts[0];
                ccExpYear  = parts[1];
            }
            this.creditCardExpYear(ccExpYear);
            this.creditCardExpMonth(ccExpMonth);

            var ccExpMonth2 = '', ccExpYear2 = '',
                ccExp2 = this.creditCardExpDate2() || '',
                parts2 = ccExp2.split('/');
            if (parts2.length >= 2) {
                ccExpMonth2 = parts2[0];
                ccExpYear2  = parts2[1];
            }
            this.creditCardExpYear2(ccExpYear2);
            this.creditCardExpMonth2(ccExpMonth2);

            return {
                method: this.getCode(),
                additional_data: {
                    payment_profile1:    this.selectedPaymentProfile(),
                    cc_type1:            this.selectedCardType(),
                    cc_exp_year1:        ccExpYear.length===4?ccExpYear: (ccExpYear? '20'+ccExpYear : ''),
                    cc_exp_month1:       ccExpMonth,
                    cc_number1:          this.creditCardNumber(),
                    cc_owner1:           this.creditCardOwner(),
                    cc_cvv1:             this.creditCardVerificationNumber(),
                    cc_installments1:    this.selectedInstallments() || 1,

                    payment_profile2:    this.selectedPaymentProfile2(),
                    cc_type2:            this.selectedCardType2(),
                    cc_exp_year2:        ccExpYear2.length===4?ccExpYear2: (ccExpYear2? '20'+ccExpYear2 : ''),
                    cc_exp_month2:       ccExpMonth2,
                    cc_number2:          this.creditCardNumber2(),
                    cc_owner2:           this.creditCardOwner2(),
                    cc_cvv2:             this.creditCardVerificationNumber2(),
                    cc_installments2:    this.selectedInstallments2() || 1,

                    document:            this.taxvat.value(),
                    amount_first_card:   this.creditAmountDisplay(),
                    amount_second_card:  this.secondCardAmountDisplay()
                }
            };
        },

        /**
         * Initialize observables and computed properties
         * @return {Component}
         */
        initObservable: function () {
            var self = this;
            this._super()
                .observe([
                    // first card
                    'creditCardExpDate','creditCardExpYear','creditCardExpMonth','creditCardNumber',
                    'vindiCreditCardNumber','creditCardOwner','creditCardVerificationNumber',
                    'selectedCardType','selectedPaymentProfile','selectedInstallments','maxInstallments',
                    // second card
                    'creditCardExpDate2','creditCardExpYear2','creditCardExpMonth2','creditCardNumber2',
                    'vindiCreditCardNumber2','creditCardOwner2','creditCardVerificationNumber2',
                    'selectedCardType2','selectedPaymentProfile2','selectedInstallments2','maxInstallments2'
                ]);

            // installment controls
            self.isInstallmentsDisabled  = ko.observable(false);
            self.isInstallmentsDisabled2 = ko.observable(false);

            setCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
                self.updateInstallments2();
            });
            cancelCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
                self.updateInstallments2();
            });

            // manual amounts
            self.creditAmountManual       = ko.observable();
            self.secondCardAmountManual   = ko.observable();
            self.selectedManualMethod     = ko.observable();

            self.orderTotal = parseFloat(totals.getSegment('grand_total').value) || 0;
            self.formattedOrderTotal = ko.computed(function() {
                return priceUtils.formatPrice(self.orderTotal, quote.getPriceFormat());
            });

            // computed displays
            self.creditAmountDisplay = ko.computed({
                read: function() {
                    if (self.selectedManualMethod() === 'secondCard') {
                        var sec = parseFloat(self.secondCardAmountManual()||0);
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

            self.secondCardAmountDisplay = ko.computed({
                read: function() {
                    if (self.selectedManualMethod() === 'firstCard') {
                        var fst = parseFloat(self.creditAmountManual()||0);
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

            // editability and invalid flags
            self.isFirstCardEditable  = ko.computed(() => self.selectedManualMethod()!=='secondCard');
            self.isSecondCardEditable = ko.computed(() => self.selectedManualMethod()!=='firstCard');
            self.firstCardInvalid  = ko.computed(() => parseFloat(self.creditAmountManual()||0) > self.orderTotal);
            self.secondCardInvalid = ko.computed(() => parseFloat(self.secondCardAmountManual()||0) > self.orderTotal);

            // subscribe updates
            self.creditAmountManual.subscribe(() => self.updateInstallments());
            self.secondCardAmountManual.subscribe(() => self.updateInstallments2());

            // card number validation
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

            this.checkPlanInstallments();
            return this;
        },

        /**
         * Validate fields
         * @return {Boolean}
         */
        validate: function () {
            var self = this,
                doc = this.taxvat.value(),
                first = parseFloat(self.creditAmountDisplay()||0),
                second = parseFloat(self.secondCardAmountDisplay()||0);

            if (!doc) {
                this.messageContainer.addErrorMessage({ message: $t('CPF/CNPJ is required') });
                return false;
            }
            if (!documentValidate.isValidTaxvat(doc)) {
                this.messageContainer.addErrorMessage({ message: $t('Invalid CPF/CNPJ') });
                return false;
            }
            if ((first + second).toFixed(2) !== self.orderTotal.toFixed(2)) {
                this.messageContainer.addErrorMessage({ message: $t('Sum of both cards must equal order total') });
                return false;
            }
            return true;
        },

        /**
         * Initialize component
         * @return {Component}
         */
        initialize: function () {
            this._super();
            this.taxvat.value(window.checkoutConfig.payment['vindi_vp_cardcard'].customer_taxvat);
            this.updateInstallments();
            this.updateInstallments2();
        },

        /**
         * Load first card UI
         */
        loadCard: function () {
            creditCardForm(
                document.getElementById(this.getCode()+'_cc_owner'),
                document.getElementById(this.getCode()+'_cc_number'),
                document.getElementById(this.getCode()+'_cc_exp_date'),
                document.getElementById(this.getCode()+'_cc_cid'),
                document.getElementById('vindi-ccsingle'),
                document.getElementById('vindi-front'),
                document.getElementById('vindi-back')
            );
        },

        /**
         * Load second card UI
         */
        loadCardSecond: function () {
            creditCardForm(
                document.getElementById(this.getCode()+'_cc_owner2'),
                document.getElementById(this.getCode()+'_cc_number2'),
                document.getElementById(this.getCode()+'_cc_exp_date2'),
                document.getElementById(this.getCode()+'_cc_cid2'),
                document.getElementById('vindi-ccsingle-second'),
                document.getElementById('vindi-front-second'),
                document.getElementById('vindi-back-second')
            );
        },

        /**
         * Always active
         * @return {Boolean}
         */
        isActive: function () {
            return true;
        },

        /**
         * Get available card types from config
         */
        getCcAvailableTypes: function () {
            return window.checkoutConfig.payment['vindi_vp_cardcard'].availableTypes;
        },

        /**
         * Get months config
         */
        getCcMonths: function () {
            return window.checkoutConfig.payment['vindi_vp_cardcard'].months['vindi_vp_cardcard'];
        },

        /**
         * Get years config
         */
        getCcYears: function () {
            return window.checkoutConfig.payment['vindi_vp_cardcard'].years['vindi_vp_cardcard'];
        },

        /**
         * Check CVV requirement
         */
        hasVerification: function () {
            return window.checkoutConfig.payment['vindi_vp_cardcard'].hasVerification['vindi_vp_cardcard'];
        },

        /**
         * Map available types to value/type
         */
        getCcAvailableTypesValues: function () {
            return _.map(this.getCcAvailableTypes(), function(v,k){ return {value:k,type:v}; });
        },

        /**
         * Check installments allowed in store
         */
        installmentsAllowed: function () {
            return parseInt(window.checkoutConfig.payment['vindi_vp_cardcard'].isInstallmentsAllowedInStore) !== 0;
        },

        /**
         * Update first card installments
         */
        updateInstallments: function(max) {
            var cfg = window.checkoutConfig.payment['vindi_vp_cardcard'],
                total = parseFloat(this.creditAmountDisplay()||0),
                arr = [], i, val;
            this.isInstallmentsDisabled(true);
            if (cfg.isInstallmentsAllowedInStore) {
                var maxI = max||cfg.maxInstallments, minV=cfg.minInstallmentsValue;
                for(i=1;i<=maxI;i++){
                    val = Math.ceil((total/i)*100)/100;
                    if(i>1 && i*minV>total) break;
                    arr.push({value:i, text:i+' x '+priceUtils.formatPrice(total/i, quote.getPriceFormat())});
                }
            }
            this.creditCardInstallments(arr.length?arr:[{value:1,text:'1 x '+priceUtils.formatPrice(total, quote.getPriceFormat())}]);
            this.isInstallmentsDisabled(false);
        },

        /**
         * Update second card installments
         */
        updateInstallments2: function(max) {
            var cfg = window.checkoutConfig.payment['vindi_vp_cardcard'],
                total = parseFloat(this.secondCardAmountDisplay()||0),
                arr=[],i,val;
            this.isInstallmentsDisabled2(true);
            if(cfg.isInstallmentsAllowedInStore){
                var maxI=max||cfg.maxInstallments, minV=cfg.minInstallmentsValue;
                for(i=1;i<=maxI;i++){
                    val=Math.ceil((total/i)*100)/100;
                    if(i>1 && i*minV>total) break;
                    arr.push({value:i, text:i+' x '+priceUtils.formatPrice(total/i, quote.getPriceFormat())});
                }
            }
            this.creditCardInstallments2(arr.length?arr:[{value:1,text:'1 x '+priceUtils.formatPrice(total,quote.getPriceFormat())}]);
            this.isInstallmentsDisabled2(false);
        },

        /**
         * Format a price
         */
        getFormattedPrice: function(price) {
            return priceUtils.formatPrice(price, quote.getPriceFormat());
        },

        /**
         * Return saved profiles
         */
        getPaymentProfiles: function() {
            var arr=[];
            var saved = window.checkoutConfig.payment['vindi_vp_cardcard'].saved_cards||[];
            saved.forEach(function(c){ arr.push({value:c.id,text:c.card_type.toUpperCase()+' xxxx-'+c.card_number}); });
            return arr;
        },

        /**
         * Check saved profiles
         */
        hasPaymentProfiles: function(){ return this.getPaymentProfiles().length>0; },
        hasPaymentProfiles2: function(){ return this.getPaymentProfiles().length>0; },

        /**
         * AJAX plan installments fetch
         */
        checkPlanInstallments: function() {
            var self=this;
            $.get(self.getUrl('vindi_vp/plan/get'), function(res){
                self.updateInstallments(res.installments);
                self.updateInstallments2(res.installments);
            });
        },

        /**
         * Build URL
         */
        getUrl: function(path) {
            return window.BASE_URL+path;
        },

        /**
         * Document field active?
         */
        isActiveDocument: function() {
            return window.checkoutConfig.payment['vindi_vp_cardcard'].enabledDocument;
        },

        /**
         * CPF/CNPJ validation on keyup
         */
        checkCpf: function(_, event) {
            this.formatTaxvat(event.target);
            var msg = documentValidate.isValidTaxvat(this.taxvat.value()) ? '' : 'CPF/CNPJ inválido';
            $('#cpfResponse').text(msg);
        },

        /**
         * Format document input
         */
        formatTaxvat: function(target) {
            taxvat.formatDocument(target);
        }
    });
});
