/**
 * Vindi
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Vindi license that is
 * available through the world-wide-web at this URL:
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to a newer
 * version in the future.
 *
 * @category   Vindi
 * @package    Vindi_VP
 * @copyright  Copyright (c) Vindi
 */

define(
    [
        'underscore',
        'ko',
        'jquery',
        'mage/translate',
        'Magento_SalesRule/js/action/set-coupon-code',
        'Magento_SalesRule/js/action/cancel-coupon',
        'Magento_Customer/js/model/customer',
        'Magento_Payment/js/view/payment/cc-form',
        'Vindi_VP/js/model/credit-card-validation/credit-card-number-validator',
        'Magento_Payment/js/model/credit-card-validation/credit-card-data',
        'Vindi_VP/js/fingerprint',
        'vindi-cc-form',
        'Magento_Payment/js/model/credit-card-validation/validator',
        'Magento_Checkout/js/model/payment/additional-validators',
        'mage/mage',
        'mage/validation',
        'vindi_vp/validation'
    ],
    function (
        _,
        ko,
        $,
        $t,
        setCouponCodeAction,
        cancelCouponCodeAction,
        customer,
        Component,
        cardNumberValidator,
        creditCardData,
        fingerprint,
        creditCardForm
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Vindi_VP/payment/form/cardpix',
                taxvat: (window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.vindi_vp_cardpix &&
                    window.checkoutConfig.payment.vindi_vp_cardpix.customer_taxvat
                ) ? window.checkoutConfig.payment.vindi_vp_cardpix.customer_taxvat.replace(/[^0-9]/g, "") : "",
                creditCardOwner: '',
                creditCardInstallments: '',
                vindiCreditCardNumber: '',
                creditCardType: '',
                showCardData: ko.observable(true),
                installments: ko.observableArray([]),
                hasInstallments: ko.observable(false),
                installmentsUrl: '',
                showInstallmentsWarning: ko.observable(false),
                debounceTimer: null,
                isCheckoutPage: ko.observable(true),
                paymentProfiles: [],
                selectedPaymentProfile: '',
                installmentsDisabled: ko.observable(true),
                saveCard: false,
                showCardError: ko.observable(false),
                showPixError: ko.observable(false),
                cardErrorMessage: ko.observable(''),
                pixErrorMessage: ko.observable(''),
                isFormValid: ko.observable(true),
                isLoadingInstallments: ko.observable(false)
            },

            /** @inheritdoc */
            initObservable: function () {
                var self = this;

                this._super().observe([
                    'taxvat',
                    'creditCardType',
                    'creditCardExpDate',
                    'creditCardExpYear',
                    'creditCardExpMonth',
                    'vindiCreditCardNumber',
                    'creditCardType',
                    'creditCardVerificationNumber',
                    'selectedCardType',
                    'creditCardOwner',
                    'creditCardInstallments',
                    'selectedPaymentProfile',
                    'saveCard',
                    'showCardError',
                    'showPixError',
                    'cardErrorMessage',
                    'pixErrorMessage',
                    'isFormValid',
                    'isLoadingInstallments'
                ]);

                this.creditCardVerificationNumber('');

                setCouponCodeAction.registerSuccessCallback(function () {
                    self.updateInstallmentsValues();
                });

                cancelCouponCodeAction.registerSuccessCallback(function () {
                    self.updateInstallmentsValues();
                });

                this.vindiCreditCardNumber.subscribe(function (value) {
                    if (!value) {
                        return false;
                    }
                    var result = cardNumberValidator(value);
                    if (!result || !result.isValid) {
                        return false;
                    }
                    if (result.card !== null) {
                        self.selectedCardType(result.card.type);
                        creditCardData.creditCard = result.card;
                    }
                    creditCardData.vindiCreditCardNumber = value;
                    self.creditCardType(result.card.type);
                    self.updateInstallmentsValues();
                });

                this.selectedPaymentProfile.subscribe(function (value) {
                    if (value) {
                        var cardProfiles = self.getPaymentProfiles();
                        var selectedCard = cardProfiles.find(function (card) {
                            return card.value === value;
                        });
                        if (selectedCard && selectedCard.card_type) {
                            self.creditCardType(selectedCard.card_type);
                        }
                    }
                    self.updateInstallmentsValues();
                });

                // Inicializa e adiciona o loader
                self.initializeLoader();
                self.installmentsDisabled(true);
                this.updateInstallmentsValues();

                // Handle card amount change
                $(document).on('change', '#card_amount', function() {
                    var grandTotal = self.getGrandTotal();
                    var cardAmount = parseFloat($(this).val() || 0);

                    self.showCardError(false);
                    self.cardErrorMessage('');
                    $(this).removeClass('error');
                    self.isFormValid(true);

                    // Validate if the amount is greater than the total
                    if (cardAmount > grandTotal) {
                        self.showCardError(true);
                        self.cardErrorMessage($t('O valor excede o valor total do pedido.'));
                        $(this).addClass('error');
                        self.isFormValid(false);
                        return;
                    }

                    if (cardAmount > 0) {
                        var remainingAmount = grandTotal - cardAmount;
                        $('#pix_amount').val(remainingAmount.toFixed(2)).prop('disabled', true);
                        self.showPixError(false);
                        self.pixErrorMessage('');
                        $('#pix_amount').removeClass('error');

                        // Update installments when card amount changes
                        self.updateInstallmentsValues();
                    }
                });

                // Handle when card amount is cleared
                $(document).on('input', '#card_amount', function() {
                    if (!$(this).val() || $(this).val() === '') {
                        $('#pix_amount').val('').prop('disabled', false);
                        self.showCardError(false);
                        self.cardErrorMessage('');
                        $(this).removeClass('error');
                        $('#pix_amount').removeClass('error');
                        self.isFormValid(true);
                        // Update installments when card amount is cleared
                        self.updateInstallmentsValues();
                    }
                });

                // Handle pix amount change
                $(document).on('change', '#pix_amount', function() {
                    var grandTotal = self.getGrandTotal();
                    var pixAmount = parseFloat($(this).val() || 0);

                    self.showPixError(false);
                    self.pixErrorMessage('');
                    $(this).removeClass('error');
                    self.isFormValid(true);

                    // Validate if the amount is greater than the total
                    if (pixAmount > grandTotal) {
                        self.showPixError(true);
                        self.pixErrorMessage($t('O valor excede o valor total do pedido.'));
                        $(this).addClass('error');
                        self.isFormValid(false);
                        return;
                    }

                    if (pixAmount > 0) {
                        var remainingAmount = grandTotal - pixAmount;
                        $('#card_amount').val(remainingAmount.toFixed(2)).prop('disabled', true);
                        self.showCardError(false);
                        self.cardErrorMessage('');
                        $('#card_amount').removeClass('error');

                        // Update installments when pix amount changes (affecting card amount)
                        self.updateInstallmentsValues();
                    }
                });

                // Handle when pix amount is cleared
                $(document).on('input', '#pix_amount', function() {
                    if (!$(this).val() || $(this).val() === '') {
                        $('#card_amount').val('').prop('disabled', false);
                        self.showPixError(false);
                        self.pixErrorMessage('');
                        $(this).removeClass('error');
                        $('#card_amount').removeClass('error');
                        self.isFormValid(true);
                        // Update installments when pix amount is cleared
                        self.updateInstallmentsValues();
                    }
                });

                return this;
            },

            /**
             * Initialize loader for installments
             */
            initializeLoader: function() {
                var self = this;

                // Add CSS for loader
                var style = document.createElement('style');
                style.type = 'text/css';
                style.innerHTML = `
                    .vindi-loader-container {
                        display: flex;
                        align-items: center;
                        margin-top: 5px;
                    }
                    .vindi-loader {
                        border: 3px solid #f3f3f3;
                        border-top: 3px solid #555;
                        border-radius: 50%;
                        width: 20px;
                        height: 20px;
                        animation: vindi-spin 1s linear infinite;
                        margin-right: 10px;
                    }
                    .vindi-loader-text {
                        font-size: 14px;
                        color: #555;
                    }
                    @keyframes vindi-spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                `;
                document.head.appendChild(style);

                // Create loader HTML
                var loaderHtml = `
                    <div class="vindi-loader-container" style="display: none;">
                        <div class="vindi-loader"></div>
                        <div class="vindi-loader-text">${$t('Carregando parcelas...')}</div>
                    </div>
                `;

                // Add loader after installments select
                this.isLoadingInstallments.subscribe(function(isLoading) {
                    setTimeout(function() {
                        var $installmentField = $('.field.installments.required');
                        var $loaderContainer = $installmentField.find('.vindi-loader-container');

                        if ($loaderContainer.length === 0 && $installmentField.length > 0) {
                            $installmentField.find('.control').append(loaderHtml);
                            $loaderContainer = $installmentField.find('.vindi-loader-container');
                        }

                        if ($loaderContainer.length > 0) {
                            if (isLoading) {
                                $loaderContainer.show();
                            } else {
                                $loaderContainer.hide();
                            }
                        }
                    }, 0);
                });
            },

            /**
             * Get validation for VAT field
             * @returns {Object}
             */
            getVatValidation: function() {
                return JSON.stringify({
                    'required-entry': true,
                    'validate-taxvat': true
                });
            },

            /**
             * Get grand total from checkout config
             * @returns {number}
             */
            getGrandTotal: function() {
                var grandTotal = 0;
                if (window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.vindi_vp_cardpix &&
                    window.checkoutConfig.payment.vindi_vp_cardpix.grand_total) {
                    grandTotal = parseFloat(window.checkoutConfig.payment.vindi_vp_cardpix.grand_total);
                }
                return grandTotal;
            },

            getCode: function () {
                return this.item.method;
            },

            /**
             * Get data
             * @returns {Object}
             */
            getData: function () {
                fingerprint(window.checkoutConfig.payment[this.getCode()].sandbox);
                var ccExpMonth = '';
                var ccExpYear = '';
                var ccExpDate = this.creditCardExpDate();
                if (typeof ccExpDate !== "undefined" && ccExpDate !== null) {
                    var ccExpDateFull = ccExpDate.split('/');
                    ccExpMonth = ccExpDateFull[0];
                    ccExpYear = ccExpDateFull[1];
                }
                // Captura os valores dos inputs de split
                var amountCredit = parseFloat($('#card_amount').val() || 0);
                var amountPix = parseFloat($('#pix_amount').val() || 0);
                return {
                    'method': this.item.method,
                    'additional_data': {
                        'payment_profile': this.selectedPaymentProfile(),
                        'taxvat': this.taxvat(),
                        'cc_cid': this.creditCardVerificationNumber(),
                        'cc_type': this.mapCardType(this.creditCardType()),
                        'cc_exp_month': ccExpMonth,
                        'cc_exp_year': ccExpYear && ccExpYear.length === 4 ? ccExpYear : '20' + ccExpYear,
                        'cc_number': this.vindiCreditCardNumber(),
                        'cc_owner': this.creditCardOwner(),
                        'installments': this.creditCardInstallments(),
                        'save_card': this.saveCard() ? 1 : 0,
                        'fingerprint': (window.yapay && window.yapay.FingerPrint) ? window.yapay.FingerPrint().getFingerPrint() : '',
                        'amount_credit': amountCredit,
                        'amount_pix': amountPix
                    }
                };
            },

            /**
             * Get list of available credit card types
             * @returns {Array}
             */
            getCcAvailableTypes: function () {
                return (
                    window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment[this.getCode()] &&
                    window.checkoutConfig.payment[this.getCode()].availableTypes
                ) ? window.checkoutConfig.payment[this.getCode()].availableTypes : [];
            },

            /**
             * Get icons
             * @param {string} type
             * @returns {boolean|Object}
             */
            getIcons: function (type) {
                var config = window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment[this.getCode()];

                if (config && config.icons && config.icons.hasOwnProperty(type)) {
                    return config.icons[type];
                }
                return false;
            },

            /**
             * Check if payment is active
             * @returns {boolean}
             */
            isActive: function () {
                return this.getCode() === this.isChecked();
            },

            /**
             * Validate form
             * @returns {boolean}
             */
            validate: function () {
                var self = this;

                try {
                    var $form = $('#' + 'form_' + this.getCode());

                    // Validate card and pix amounts
                    var cardAmount = parseFloat($('#card_amount').val() || 0);
                    var pixAmount = parseFloat($('#pix_amount').val() || 0);
                    var grandTotal = this.getGrandTotal();

                    // Reset error states
                    this.showCardError(false);
                    this.showPixError(false);
                    $('#card_amount').removeClass('error');
                    $('#pix_amount').removeClass('error');
                    this.isFormValid(true);

                    // Validate card amount
                    if (cardAmount > grandTotal) {
                        this.showCardError(true);
                        this.cardErrorMessage($t('O valor excede o valor total do pedido.'));
                        $('#card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Validate pix amount
                    if (pixAmount > grandTotal) {
                        this.showPixError(true);
                        this.pixErrorMessage($t('O valor excede o valor total do pedido.'));
                        $('#pix_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Validate total of both payment methods with tolerance for floating point errors
                    var totalAmount = Math.round((cardAmount + pixAmount) * 100) / 100;
                    var roundedGrandTotal = Math.round(grandTotal * 100) / 100;

                    if (totalAmount > roundedGrandTotal + 0.01) { // Adding small tolerance (0.01)
                        this.showCardError(true);
                        this.cardErrorMessage($t('A soma dos valores excede o total do pedido.'));
                        $('#card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Validate if at least one payment method is selected
                    if (totalAmount === 0 || isNaN(totalAmount)) {
                        this.showCardError(true);
                        this.cardErrorMessage($t('Informe um valor para pelo menos um método de pagamento.'));
                        $('#card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Handle manual form validation instead of using jQuery validation plugin
                    if ($form && $form.length) {
                        var isValid = true;

                        // Validate required fields
                        $form.find('input[data-validate], select[data-validate]').each(function() {
                            var $field = $(this);

                            // Skip validation for fields in hidden sections
                            if ($field.is(':hidden') || $field.closest('.field').is(':hidden')) {
                                return;
                            }

                            // Skip validation for payment profile when selected
                            if (self.selectedPaymentProfile() && ($field.attr('id') === (self.getCode() + '_cc_number') ||
                                $field.attr('id') === (self.getCode() + '_cc_owner') ||
                                $field.attr('id') === (self.getCode() + '_cc_exp_date') ||
                                $field.attr('id') === (self.getCode() + '_cc_cid'))) {
                                return;
                            }

                            // Skip validation for fields when card amount is 0
                            if (cardAmount === 0 && ($field.attr('id') === (self.getCode() + '_cc_installments') ||
                                $field.attr('id') === (self.getCode() + '_cc_number') ||
                                $field.attr('id') === (self.getCode() + '_cc_owner') ||
                                $field.attr('id') === (self.getCode() + '_cc_exp_date') ||
                                $field.attr('id') === (self.getCode() + '_cc_cid'))) {
                                return;
                            }

                            // Skip validation for taxvat when pix amount is 0
                            if (pixAmount === 0 && $field.attr('id') === (self.getCode() + '_taxvat')) {
                                return;
                            }

                            if ($field.val() === '') {
                                isValid = false;
                                $field.addClass('mage-error');
                            } else {
                                $field.removeClass('mage-error');
                            }
                        });

                        if (!isValid) {
                            return false;
                        }
                    }

                    return true;
                } catch (e) {
                    console.error('Erro durante validação:', e);
                    return true; // Em caso de erro na validação, permite continuar
                }
            },

            /**
             * Override placeOrder to add custom validation
             */
            placeOrder: function (data, event) {
                if (event) {
                    event.preventDefault();
                }

                try {
                    if (this.validate()) {
                        this.isPlaceOrderActionAllowed(false);

                        this.getPlaceOrderDeferredObject()
                            .done(
                                function () {
                                    self.afterPlaceOrder();

                                    if (self.redirectAfterPlaceOrder) {
                                        redirectOnSuccessAction.execute();
                                    }
                                }
                            ).always(
                            function () {
                                self.isPlaceOrderActionAllowed(true);
                            }
                        );

                        return true;
                    }
                } catch (e) {
                    console.error('Erro ao processar pedido:', e);
                    this.isPlaceOrderActionAllowed(true);
                }

                return false;
            },

            /**
             * Retrieve installments URL
             * @returns {string}
             */
            retrieveInstallmentsUrl: function () {
                try {
                    return window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.ccform &&
                    window.checkoutConfig.payment.ccform.urls &&
                    window.checkoutConfig.payment.ccform.urls[this.getCode()] &&
                    window.checkoutConfig.payment.ccform.urls[this.getCode()].retrieve_installments
                        ? window.checkoutConfig.payment.ccform.urls[this.getCode()].retrieve_installments
                        : '';
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.log('Installments URL not defined');
                    return "";
                }
            },

            /**
             * Check if user is logged in
             * @returns {boolean}
             */
            isLoggedIn: function () {
                return customer.isLoggedIn();
            },

            /**
             * Map card type
             * @param {string} type
             * @returns {string}
             */
            mapCardType: function (type) {
                var mapping = {
                    'Mastercard': 'MC',
                    'Aura': 'AU',
                    'Visa': 'VI',
                    'Elo': 'ELO',
                    'American Express': 'AE',
                    'JCB': 'JCB',
                    'Hipercard': 'HC',
                    'Hiper': 'HI'
                };
                return mapping[type] ? mapping[type] : type;
            },

            /**
             * Update installments values
             */
            updateInstallmentsValues: function () {
                var self = this;
                self.installmentsDisabled(true);

                // Mostrar o loader
                self.isLoadingInstallments(true);

                if (self.debounceTimer !== null) {
                    clearTimeout(self.debounceTimer);
                }

                self.debounceTimer = setTimeout(function () {
                    var url = self.retrieveInstallmentsUrl();
                    if (!url || typeof fetch !== "function") {
                        self.installmentsDisabled(false);
                        self.isLoadingInstallments(false);
                        return;
                    }

                    // Get card amount value from form
                    var cardAmount = parseFloat($('#card_amount').val() || 0);

                    fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            cc_type: self.creditCardType(),
                            payment_link: {
                                grand_total: cardAmount
                            }
                        })
                    }).then(function (response) {
                        return response.json();
                    }).then(function (json) {
                        self.installments(json);
                        self.hasInstallments(json.length > 0);
                        self.installmentsDisabled(false);
                        self.isLoadingInstallments(false);
                    }).catch(function () {
                        self.installmentsDisabled(false);
                        self.isLoadingInstallments(false);
                    });
                }, 500);
            },

            /**
             * Get payment profiles
             * @returns {Array}
             */
            getPaymentProfiles: function () {
                var paymentProfiles = [];
                var savedCards = window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.vindi_vp_cardpix &&
                    window.checkoutConfig.payment.vindi_vp_cardpix.saved_cards;

                if (savedCards && Array.isArray(savedCards)) {
                    savedCards.forEach(function (card) {
                        paymentProfiles.push({
                            'value': card.id,
                            'text': card.card_type + ' xxxx-' + card.card_number,
                            'card_type': card.card_type
                        });
                    });
                }
                return paymentProfiles;
            },

            /**
             * Check if user has payment profiles
             * @returns {boolean}
             */
            hasPaymentProfiles: function () {
                return this.getPaymentProfiles().length > 0;
            }
        });
    }
);
