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
        'Magento_Checkout/js/action/redirect-on-success',
        'mage/mage',
        'mage/validation',
        'vindi_vp/validation',
        'jquery/jquery.mask'
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
        creditCardForm,
        validator,
        additionalValidators,
        redirectOnSuccessAction
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
                showPercentageErrorCard: ko.observable(false),
                showPercentageErrorPix: ko.observable(false),
                percentageErrorCard: ko.observable(''),
                percentageErrorPix: ko.observable(''),
                isFormValid: ko.observable(true),
                isLoadingInstallments: ko.observable(false),
                isPlaceOrderActionAllowed: ko.observable(true)
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
                    'showPercentageErrorCard',
                    'showPercentageErrorPix',
                    'percentageErrorCard',
                    'percentageErrorPix',
                    'isFormValid',
                    'isLoadingInstallments',
                    'isPlaceOrderActionAllowed'
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
                this.initializeMasks();

                // Handle card amount change
                $(document).on('change', '#card_amount', function() {
                    var grandTotal = self.getGrandTotal();
                    var cardAmount = parseFloat($(this).val() || 0);

                    // Reset all error states
                    self.showCardError(false);
                    self.showPercentageErrorCard(false);
                    self.cardErrorMessage('');
                    self.percentageErrorCard('');
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

                    // Validate negative values
                    if (cardAmount < 0) {
                        self.showCardError(true);
                        self.cardErrorMessage($t('Valor inválido'));
                        $(this).addClass('error');
                        self.isFormValid(false);
                        return;
                    }

                    // Validate decimal places
                    var cardAmountStr = $(this).val();
                    if (cardAmountStr && cardAmountStr.includes(',') && cardAmountStr.split(',')[1] && cardAmountStr.split(',')[1].length > 2) {
                        self.showCardError(true);
                        self.cardErrorMessage($t('Casas decimais excedem duas posições.'));
                        $(this).addClass('error');
                        self.isFormValid(false);
                        return;
                    }

                    if (cardAmount > 0) {
                        // Validate percentage rule (5% minimum)
                        var percentageValidation = self.validatePercentage(cardAmount, grandTotal, 'cartão');
                        if (!percentageValidation.valid) {
                            self.showPercentageErrorCard(true);
                            self.percentageErrorCard(percentageValidation.detailedMessage);
                            $(this).addClass('error');
                            self.isFormValid(false);
                            return;
                        }

                        var remainingAmount = grandTotal - cardAmount;
                        
                        // Validate if remaining amount for PIX meets 5% rule
                        if (remainingAmount > 0) {
                            var pixPercentageValidation = self.validatePercentage(remainingAmount, grandTotal, 'PIX');
                            if (!pixPercentageValidation.valid) {
                                self.showPercentageErrorCard(true);
                                self.percentageErrorCard(pixPercentageValidation.detailedMessage);
                                $(this).addClass('error');
                                self.isFormValid(false);
                                return;
                            }
                        }

                        $('#pix_amount').val(remainingAmount.toFixed(2).replace('.', ',')).prop('disabled', true);
                        self.showPixError(false);
                        self.showPercentageErrorPix(false);
                        self.pixErrorMessage('');
                        self.percentageErrorPix('');
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
                        self.showPercentageErrorCard(false);
                        self.cardErrorMessage('');
                        self.percentageErrorCard('');
                        $(this).removeClass('error');
                        $('#pix_amount').removeClass('error');
                        self.showPixError(false);
                        self.showPercentageErrorPix(false);
                        self.pixErrorMessage('');
                        self.percentageErrorPix('');
                        self.isFormValid(true);
                        // Update installments when card amount is cleared
                        self.updateInstallmentsValues();
                    }
                });

                // Handle pix amount change
                $(document).on('change', '#pix_amount', function() {
                    var grandTotal = self.getGrandTotal();
                    var pixAmount = parseFloat($(this).val().replace(',', '.') || 0);

                    // Reset all error states
                    self.showPixError(false);
                    self.showPercentageErrorPix(false);
                    self.pixErrorMessage('');
                    self.percentageErrorPix('');
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

                    // Validate negative values
                    if (pixAmount < 0) {
                        self.showPixError(true);
                        self.pixErrorMessage($t('Valor inválido'));
                        $(this).addClass('error');
                        self.isFormValid(false);
                        return;
                    }

                    // Validate decimal places
                    var pixAmountStr = $(this).val();
                    if (pixAmountStr && pixAmountStr.includes(',') && pixAmountStr.split(',')[1] && pixAmountStr.split(',')[1].length > 2) {
                        self.showPixError(true);
                        self.pixErrorMessage($t('Casas decimais excedem duas posições.'));
                        $(this).addClass('error');
                        self.isFormValid(false);
                        return;
                    }

                    if (pixAmount > 0) {
                        // Validate percentage rule (5% minimum)
                        var percentageValidation = self.validatePercentage(pixAmount, grandTotal, 'PIX');
                        if (!percentageValidation.valid) {
                            self.showPercentageErrorPix(true);
                            self.percentageErrorPix(percentageValidation.detailedMessage);
                            $(this).addClass('error');
                            self.isFormValid(false);
                            return;
                        }

                        var remainingAmount = grandTotal - pixAmount;
                        
                        // Validate if remaining amount for card meets 5% rule
                        if (remainingAmount > 0) {
                            var cardPercentageValidation = self.validatePercentage(remainingAmount, grandTotal, 'cartão');
                            if (!cardPercentageValidation.valid) {
                                self.showPercentageErrorPix(true);
                                self.percentageErrorPix(cardPercentageValidation.detailedMessage);
                                $(this).addClass('error');
                                self.isFormValid(false);
                                return;
                            }
                        }

                        $('#card_amount').val(remainingAmount.toFixed(2).replace('.', ',')).prop('disabled', true);
                        self.showCardError(false);
                        self.showPercentageErrorCard(false);
                        self.cardErrorMessage('');
                        self.percentageErrorCard('');
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
                        self.showPercentageErrorPix(false);
                        self.pixErrorMessage('');
                        self.percentageErrorPix('');
                        $(this).removeClass('error');
                        $('#card_amount').removeClass('error');
                        self.showCardError(false);
                        self.showPercentageErrorCard(false);
                        self.cardErrorMessage('');
                        self.percentageErrorCard('');
                        self.isFormValid(true);
                        // Update installments when pix amount is cleared
                        self.updateInstallmentsValues();
                    }
                });

                // Add blur validation for real-time feedback
                $(document).on('blur', '#card_amount, #pix_amount', function() {
                    var fieldId = $(this).attr('id');
                    var amount = parseFloat($(this).val().replace(',', '.') || 0);
                    var grandTotal = self.getGrandTotal();
                    var isCardField = fieldId === 'card_amount';

                    if (amount > 0) {
                        // Validate percentage rule
                        var methodName = isCardField ? 'cartão' : 'PIX';
                        var percentageValidation = self.validatePercentage(amount, grandTotal, methodName);
                        
                        if (!percentageValidation.valid) {
                            if (isCardField) {
                                self.showPercentageErrorCard(true);
                                self.percentageErrorCard(percentageValidation.detailedMessage);
                            } else {
                                self.showPercentageErrorPix(true);
                                self.percentageErrorPix(percentageValidation.detailedMessage);
                            }
                            $(this).addClass('error');
                            self.isFormValid(false);
                            return;
                        }

                        // Clear percentage errors if validation passes
                        if (isCardField) {
                            self.showPercentageErrorCard(false);
                            self.percentageErrorCard('');
                        } else {
                            self.showPercentageErrorPix(false);
                            self.percentageErrorPix('');
                        }
                        $(this).removeClass('error');
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
                if (typeof ccExpDate !== "undefined" && ccExpDate !== null && ccExpDate.length === 5) {
                    var ccExpDateFull = ccExpDate.split('/');
                    ccExpMonth = ccExpDateFull[0];
                    ccExpYear = ccExpDateFull[1].length === 2 ? '20' + ccExpDateFull[1] : ccExpDateFull[1];
                }

                // Capturar valores do multimeios
                var cardAmount = parseFloat($('#card_amount').val() || 0);
                var pixAmount = parseFloat($('#pix_amount').val() || 0);

                return {
                    'method': this.item.method,
                    'additional_data': {
                        'payment_profile': this.selectedPaymentProfile(),
                        'taxvat': this.taxvat(),
                        'cc_cid': this.creditCardVerificationNumber(),
                        'cc_type': this.mapCardType(this.creditCardType()),
                        'cc_exp_month': ccExpMonth,
                        'cc_exp_year': ccExpYear,
                        'cc_number': this.vindiCreditCardNumber(),
                        'cc_owner': this.creditCardOwner(),
                        'installments': this.creditCardInstallments(),
                        'save_card': this.saveCard() ? 1 : 0,
                        'fingerprint': (window.yapay && window.yapay.FingerPrint) ? window.yapay.FingerPrint().getFingerPrint() : '',
                        'amount_credit': cardAmount,
                        'amount_pix': pixAmount
                    }
                };
            },

            /**
             * Get list of available credit card types
             * @returns {Array}
             */
            getCcAvailableTypes: function () {
                var ccMethod = 'vindi_vp_cc';
                if (window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment[ccMethod] &&
                    window.checkoutConfig.payment[ccMethod].availableTypes) {
                    return window.checkoutConfig.payment[ccMethod].availableTypes;
                }
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
             * Validate percentage rule (minimum 5% per method)
             * @param {number} amount
             * @param {number} total
             * @param {string} methodName
             * @returns {Object}
             */
            validatePercentage: function(amount, total, methodName) {
                var percentage = (amount / total) * 100;
                var minPercentage = 5;
                var maxPercentage = 95;
                var minAmount = (total * minPercentage) / 100;
                var maxAmount = (total * maxPercentage) / 100;

                if (amount > 0 && percentage < minPercentage) {
                    return {
                        valid: false,
                        message: $t('Para usar multi-métodos, cada método deve ter pelo menos 5% do valor total'),
                        detailedMessage: $t('Valor muito baixo. O mínimo é 5% do total (R$ %1)').replace('%1', minAmount.toFixed(2).replace('.', ','))
                    };
                }

                if (amount > maxAmount) {
                    return {
                        valid: false,
                        message: $t('Para usar multi-métodos, cada método deve ter pelo menos 5% do valor total'),
                        detailedMessage: $t('Valor muito alto. O máximo é 95% do total (R$ %1)').replace('%1', maxAmount.toFixed(2).replace('.', ','))
                    };
                }

                return { valid: true };
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
                    var cardAmount = parseFloat($('#card_amount').val().replace(',', '.') || 0);
                    var pixAmount = parseFloat($('#pix_amount').val().replace(',', '.') || 0);
                    var grandTotal = this.getGrandTotal();

                    // Reset error states
                    this.showCardError(false);
                    this.showPixError(false);
                    this.showPercentageErrorCard(false);
                    this.showPercentageErrorPix(false);
                    $('#card_amount').removeClass('error');
                    $('#pix_amount').removeClass('error');
                    this.isFormValid(true);

                    // Validate negative values
                    if (cardAmount < 0) {
                        this.showCardError(true);
                        this.cardErrorMessage($t('Valor inválido'));
                        $('#card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    if (pixAmount < 0) {
                        this.showPixError(true);
                        this.pixErrorMessage($t('Valor inválido'));
                        $('#pix_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Validate decimal places
                    var cardAmountStr = $('#card_amount').val();
                    if (cardAmountStr && cardAmountStr.includes(',') && cardAmountStr.split(',')[1] && cardAmountStr.split(',')[1].length > 2) {
                        this.showCardError(true);
                        this.cardErrorMessage($t('Casas decimais excedem duas posições.'));
                        $('#card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    var pixAmountStr = $('#pix_amount').val();
                    if (pixAmountStr && pixAmountStr.includes(',') && pixAmountStr.split(',')[1] && pixAmountStr.split(',')[1].length > 2) {
                        this.showPixError(true);
                        this.pixErrorMessage($t('Casas decimais excedem duas posições.'));
                        $('#pix_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

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

                    // Validate percentage rule (5% minimum for each method)
                    if (cardAmount > 0) {
                        var cardPercentageValidation = this.validatePercentage(cardAmount, grandTotal, 'cartão');
                        if (!cardPercentageValidation.valid) {
                            this.showPercentageErrorCard(true);
                            this.percentageErrorCard(cardPercentageValidation.detailedMessage);
                            $('#card_amount').addClass('error');
                            this.isFormValid(false);
                            return false;
                        }
                    }

                    if (pixAmount > 0) {
                        var pixPercentageValidation = this.validatePercentage(pixAmount, grandTotal, 'PIX');
                        if (!pixPercentageValidation.valid) {
                            this.showPercentageErrorPix(true);
                            this.percentageErrorPix(pixPercentageValidation.detailedMessage);
                            $('#pix_amount').addClass('error');
                            this.isFormValid(false);
                            return false;
                        }
                    }

                    // Validate installments match card amount
                    if (cardAmount > 0 && this.creditCardInstallments()) {
                        var selectedInstallment = this.installments().find(function(inst) {
                            return inst.installments == self.creditCardInstallments();
                        });
                        
                        if (selectedInstallment && Math.abs(selectedInstallment.amount - cardAmount) > 0.01) {
                            this.showCardError(true);
                            this.cardErrorMessage($t('Valor das parcelas não corresponde ao valor selecionado para o cartão.'));
                            $('#card_amount').addClass('error');
                            this.isFormValid(false);
                            return false;
                        }
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

                            // CVV validation is always required for both new and saved cards
                            // Skip validation for payment profile when selected
                            if (self.selectedPaymentProfile() && ($field.attr('id') === (self.getCode() + '_cc_number') ||
                                $field.attr('id') === (self.getCode() + '_cc_owner') ||
                                $field.attr('id') === (self.getCode() + '_cc_exp_date'))) {
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
                var self = this;

                if (event) {
                    event.preventDefault();
                }

                try {
                    if (this.validate()) {
                        this.isPlaceOrderActionAllowed(false);

                        this.getPlaceOrderDeferredObject()
                            .done(
                                function () {
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
            },

            /**
             * Initialize masks for input fields
             */
            initializeMasks: function() {
                setTimeout(function() {
                    $('input[name="payment[cc_exp_date]"]').mask('00/00');
                    $('.cpf-cnpj').mask('000.000.000-00', {
                        onKeyPress: function(cpf, e, field, options) {
                            const masks = ['000.000.000-000', '00.000.000/0000-00'];
                            const mask = (cpf.length > 14) ? masks[1] : masks[0];
                            $('.cpf-cnpj').mask(mask, options);
                        }
                    });
                    $('#card_amount, #pix_amount').mask('#.##0,00', {reverse: true});
                }, 500);
            },

            /**
             * After render callback
             */
            afterRender: function() {
                var self = this;
                self.initializeMasks();
            }
        });
    }
);
