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
                template: 'Vindi_VP/payment/form/cardcard',
                taxvat: (window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.vindi_vp_cardcard &&
                    window.checkoutConfig.payment.vindi_vp_cardcard.customer_taxvat
                ) ? window.checkoutConfig.payment.vindi_vp_cardcard.customer_taxvat.replace(/[^0-9]/g, "") : "",
                creditCardOwner: '',
                secondCreditCardOwner: '',
                creditCardInstallments: '',
                secondCreditCardInstallments: '',
                vindiCreditCardNumber: '',
                secondVindiCreditCardNumber: '',
                creditCardType: '',
                secondCreditCardType: '',
                creditCardExpDate: '',
                secondCreditCardExpDate: '',
                creditCardVerificationNumber: '',
                secondCreditCardVerificationNumber: '',
                selectedCardType: '',
                secondSelectedCardType: '',
                showCardData: ko.observable(true),
                installments: ko.observableArray([]),
                secondInstallments: ko.observableArray([]),
                hasInstallments: ko.observable(false),
                hasSecondInstallments: ko.observable(false),
                installmentsUrl: '',
                showInstallmentsWarning: ko.observable(false),
                debounceTimer: null,
                secondDebounceTimer: null,
                isCheckoutPage: ko.observable(true),
                paymentProfiles: [],
                selectedPaymentProfile: '',
                secondSelectedPaymentProfile: '',
                installmentsDisabled: ko.observable(true),
                secondInstallmentsDisabled: ko.observable(true),
                saveCard: false,
                secondSaveCard: false,
                showFirstCardError: ko.observable(false),
                showSecondCardError: ko.observable(false),
                firstCardErrorMessage: ko.observable(''),
                secondCardErrorMessage: ko.observable(''),
                isFormValid: ko.observable(true),
                isLoadingInstallments: ko.observable(false),
                isLoadingSecondInstallments: ko.observable(false)
            },

            /** @inheritdoc */
            initObservable: function () {
                var self = this;

                this._super().observe([
                    'taxvat',
                    'creditCardType',
                    'secondCreditCardType',
                    'creditCardExpDate',
                    'secondCreditCardExpDate',
                    'creditCardExpYear',
                    'secondCreditCardExpYear',
                    'creditCardExpMonth',
                    'secondCreditCardExpMonth',
                    'vindiCreditCardNumber',
                    'secondVindiCreditCardNumber',
                    'creditCardVerificationNumber',
                    'secondCreditCardVerificationNumber',
                    'selectedCardType',
                    'secondSelectedCardType',
                    'creditCardOwner',
                    'secondCreditCardOwner',
                    'creditCardInstallments',
                    'secondCreditCardInstallments',
                    'selectedPaymentProfile',
                    'secondSelectedPaymentProfile',
                    'saveCard',
                    'secondSaveCard',
                    'showFirstCardError',
                    'showSecondCardError',
                    'firstCardErrorMessage',
                    'secondCardErrorMessage',
                    'isFormValid',
                    'isLoadingInstallments',
                    'isLoadingSecondInstallments'
                ]);

                this.creditCardVerificationNumber('');
                this.secondCreditCardVerificationNumber('');

                setCouponCodeAction.registerSuccessCallback(function () {
                    self.updateInstallmentsValues();
                    self.updateSecondInstallmentsValues();
                });

                cancelCouponCodeAction.registerSuccessCallback(function () {
                    self.updateInstallmentsValues();
                    self.updateSecondInstallmentsValues();
                });

                this.vindiCreditCardNumber.subscribe(function (value) {
                    console.log('[CARDCARD] vindiCreditCardNumber.subscribe called with value:', value);
                    if (!value) {
                        console.log('[CARDCARD] vindiCreditCardNumber - value is empty, returning');
                        return;
                    }
                    var result = cardNumberValidator(value);
                    console.log('[CARDCARD] cardNumberValidator result:', result);
                    if (!result || !result.isValid) {
                        console.log('[CARDCARD] cardNumberValidator - result invalid, returning');
                        return;
                    }
                    if (result.card !== null) {
                        console.log('[CARDCARD] Setting selectedCardType to:', result.card.type);
                        self.selectedCardType(result.card.type);
                    }
                    creditCardData.vindiCreditCardNumber = value;
                    console.log('[CARDCARD] Setting creditCardType to:', result.card.type);
                    self.creditCardType(result.card.type);
                    console.log('[CARDCARD] Calling updateInstallmentsValues from subscriber');
                    self.updateInstallmentsValues();
                });

                this.secondVindiCreditCardNumber.subscribe(function (value) {
                    console.log('[CARDCARD] secondVindiCreditCardNumber.subscribe called with value:', value);
                    if (!value) {
                        console.log('[CARDCARD] secondVindiCreditCardNumber - value is empty, returning');
                        return;
                    }
                    var result = cardNumberValidator(value);
                    console.log('[CARDCARD] secondVindiCreditCardNumber cardNumberValidator result:', result);
                    if (!result || !result.isValid) {
                        console.log('[CARDCARD] secondVindiCreditCardNumber - result invalid, returning');
                        return;
                    }
                    if (result.card !== null) {
                        console.log('[CARDCARD] Setting secondSelectedCardType to:', result.card.type);
                        self.secondSelectedCardType(result.card.type);
                    }
                    console.log('[CARDCARD] Setting secondCreditCardType to:', result.card.type);
                    self.secondCreditCardType(result.card.type);
                    console.log('[CARDCARD] Calling updateSecondInstallmentsValues from subscriber');
                    self.updateSecondInstallmentsValues();
                });

                this.selectedPaymentProfile.subscribe(function (value) {
                    // Para cartão salvo, mostrar apenas campos obrigatórios (CVV)
                    // mas manter o formulário visível
                    self.updateInstallmentsValues();
                });

                this.secondSelectedPaymentProfile.subscribe(function (value) {
                    // Para segundo cartão salvo, mostrar apenas campos obrigatórios (CVV)
                    // mas manter o formulário visível
                    self.updateSecondInstallmentsValues();
                });

                // Inicializa e adiciona o loader
                self.initializeLoader();
                self.installmentsDisabled(true);
                self.secondInstallmentsDisabled(true);
                this.updateInstallmentsValues();
                this.updateSecondInstallmentsValues();

                // Handle first card amount change
                $(document).on('change', '#first_card_amount', function() {
                    var grandTotal = self.getGrandTotal();
                    var firstCardAmount = parseFloat($(this).val() || 0);
                    var secondCardAmount = parseFloat($('#second_card_amount').val() || 0);

                    self.showFirstCardError(false);
                    self.firstCardErrorMessage('');
                    $(this).removeClass('error');
                    self.isFormValid(true);

                    // Validate if the amount is greater than the total
                    if (firstCardAmount > grandTotal) {
                        self.showFirstCardError(true);
                        self.firstCardErrorMessage($t('O valor não pode ser maior que o total do pedido.'));
                        $(this).addClass('error');
                        self.isFormValid(false);
                        return;
                    }

                    // Validate total of both payment methods
                    var totalAmount = Math.round((firstCardAmount + secondCardAmount) * 100) / 100;
                    var roundedGrandTotal = Math.round(grandTotal * 100) / 100;

                    if (totalAmount > roundedGrandTotal + 0.01) { // Adding small tolerance (0.01)
                        self.showFirstCardError(true);
                        self.firstCardErrorMessage($t('A soma dos valores dos cartões não pode exceder o total do pedido.'));
                        $(this).addClass('error');
                        self.isFormValid(false);
                        return;
                    }

                    if (firstCardAmount > 0) {
                        self.updateInstallmentsValues();
                    }
                });

                // Handle when first card amount is cleared
                $(document).on('input', '#first_card_amount', function() {
                    if (!$(this).val() || $(this).val() === '') {
                        self.installmentsDisabled(true);
                        self.hasInstallments(false);
                        self.installments([]);
                        self.creditCardInstallments('');
                        self.showFirstCardError(false);
                        self.firstCardErrorMessage('');
                        $(this).removeClass('error');
                    }
                });

                // Handle second card amount change
                $(document).on('change', '#second_card_amount', function() {
                    var grandTotal = self.getGrandTotal();
                    var secondCardAmount = parseFloat($(this).val() || 0);
                    var firstCardAmount = parseFloat($('#first_card_amount').val() || 0);

                    self.showSecondCardError(false);
                    self.secondCardErrorMessage('');
                    $(this).removeClass('error');
                    self.isFormValid(true);

                    // Validate if the amount is greater than the total
                    if (secondCardAmount > grandTotal) {
                        self.showSecondCardError(true);
                        self.secondCardErrorMessage($t('O valor não pode ser maior que o total do pedido.'));
                        $(this).addClass('error');
                        self.isFormValid(false);
                        return;
                    }

                    // Validate total of both payment methods
                    var totalAmount = Math.round((firstCardAmount + secondCardAmount) * 100) / 100;
                    var roundedGrandTotal = Math.round(grandTotal * 100) / 100;

                    if (totalAmount > roundedGrandTotal + 0.01) { // Adding small tolerance (0.01)
                        self.showSecondCardError(true);
                        self.secondCardErrorMessage($t('A soma dos valores dos cartões não pode exceder o total do pedido.'));
                        $(this).addClass('error');
                        self.isFormValid(false);
                        return;
                    }

                    if (secondCardAmount > 0) {
                        self.updateSecondInstallmentsValues();
                    }
                });

                // Handle when second card amount is cleared
                $(document).on('input', '#second_card_amount', function() {
                    if (!$(this).val() || $(this).val() === '') {
                        self.secondInstallmentsDisabled(true);
                        self.hasSecondInstallments(false);
                        self.secondInstallments([]);
                        self.secondCreditCardInstallments('');
                        self.showSecondCardError(false);
                        self.secondCardErrorMessage('');
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
                        var $installmentField = $('.field.first_installments.required');
                        var $loaderContainer = $installmentField.find('.vindi-loader-container');

                        if ($loaderContainer.length === 0 && $installmentField.length > 0) {
                            $installmentField.find('.control').after(loaderHtml);
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

                // Add loader after second installments select
                this.isLoadingSecondInstallments.subscribe(function(isLoading) {
                    setTimeout(function() {
                        var $installmentField = $('.field.second_installments.required');
                        var $loaderContainer = $installmentField.find('.vindi-loader-container');

                        if ($loaderContainer.length === 0 && $installmentField.length > 0) {
                            $installmentField.find('.control').after(loaderHtml);
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
                    window.checkoutConfig.payment.vindi_vp_cardcard &&
                    window.checkoutConfig.payment.vindi_vp_cardcard.grand_total) {
                    grandTotal = parseFloat(window.checkoutConfig.payment.vindi_vp_cardcard.grand_total);
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
                // Inicializar fingerprint de forma segura
                this.initializeFingerprint();

                var ccExpMonth = '';
                var ccExpYear = '';
                var ccExpDate = this.creditCardExpDate();

                var secondCcExpMonth = '';
                var secondCcExpYear = '';
                var secondCcExpDate = this.secondCreditCardExpDate();

                if (typeof ccExpDate !== "undefined" && ccExpDate !== null) {
                    var ccExpDateFull = ccExpDate.split('/');
                    ccExpMonth = ccExpDateFull[0];
                    ccExpYear = ccExpDateFull[1];
                }

                if (typeof secondCcExpDate !== "undefined" && secondCcExpDate !== null) {
                    var secondCcExpDateFull = secondCcExpDate.split('/');
                    secondCcExpMonth = secondCcExpDateFull[0];
                    secondCcExpYear = secondCcExpDateFull[1];
                }

                return {
                    'method': this.item.method,
                    'additional_data': {
                        'payment_profile': this.selectedPaymentProfile(),
                        'second_payment_profile': this.secondSelectedPaymentProfile(),
                        'taxvat': this.taxvat(),
                        'cc_cid': this.creditCardVerificationNumber(),
                        'cc_cid_2': this.secondCreditCardVerificationNumber(),
                        'cc_type': this.mapCardType(this.creditCardType()),
                        'cc_type_2': this.mapCardType(this.secondCreditCardType()),
                        'cc_exp_month': ccExpMonth,
                        'cc_exp_month_2': secondCcExpMonth,
                        'cc_exp_year': ccExpYear && ccExpYear.length === 4 ? ccExpYear : '20' + ccExpYear,
                        'cc_exp_year_2': secondCcExpYear && secondCcExpYear.length === 4 ? secondCcExpYear : '20' + secondCcExpYear,
                        'cc_number': this.vindiCreditCardNumber(),
                        'cc_number_2': this.secondVindiCreditCardNumber(),
                        'cc_owner': this.creditCardOwner(),
                        'cc_owner_2': this.secondCreditCardOwner(),
                        'installments': this.creditCardInstallments(),
                        'installments_2': this.secondCreditCardInstallments(),
                        'amount_card1': $('#first_card_amount').val(),
                        'amount_card2': $('#second_card_amount').val(),
                        'save_card': this.saveCard() ? 1 : 0,
                        'save_card_2': this.secondSaveCard() ? 1 : 0,
                        'fingerprint': this.getFingerprint(),
                        'fingerprint_2': this.getFingerprint()
                    }
                };
            },

            /**
             * Initialize fingerprint safely
             */
            initializeFingerprint: function() {
                try {
                    if (window.checkoutConfig && window.checkoutConfig.payment && window.checkoutConfig.payment[this.getCode()]) {
                        var sandbox = window.checkoutConfig.payment[this.getCode()].sandbox;
                        if (typeof fingerprint === 'function') {
                            fingerprint(sandbox);
                        }
                    }
                } catch (e) {
                    console.warn('Error initializing fingerprint:', e);
                }
            },

            /**
             * Get fingerprint safely
             * @returns {string}
             */
            getFingerprint: function() {
                try {
                    if (window.yapay && window.yapay.FingerPrint && typeof window.yapay.FingerPrint().getFingerPrint === 'function') {
                        return window.yapay.FingerPrint().getFingerPrint();
                    }
                } catch (e) {
                    console.warn('Error getting fingerprint:', e);
                }
                return '';
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
             * Get credit card types values for the credit card type selector
             * @returns {Array}
             */
            getCcAvailableTypesValues: function () {
                var types = [];
                var availableTypes = this.getCcAvailableTypes();

                if (availableTypes && typeof availableTypes === 'object') {
                    $.each(availableTypes, function (code, name) {
                        types.push({
                            'value': code,
                            'type': name
                        });
                    });
                }

                return types;
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

                    // Validate card amounts
                    var firstCardAmount = parseFloat($('#first_card_amount').val() || 0);
                    var secondCardAmount = parseFloat($('#second_card_amount').val() || 0);
                    var grandTotal = this.getGrandTotal();

                    // Reset error states
                    this.showFirstCardError(false);
                    this.showSecondCardError(false);
                    $('#first_card_amount').removeClass('error');
                    $('#second_card_amount').removeClass('error');
                    this.isFormValid(true);

                    // Validate first card amount
                    if (firstCardAmount > grandTotal) {
                        this.showFirstCardError(true);
                        this.firstCardErrorMessage($t('O valor não pode ser maior que o total do pedido.'));
                        $('#first_card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Validate second card amount
                    if (secondCardAmount > grandTotal) {
                        this.showSecondCardError(true);
                        this.secondCardErrorMessage($t('O valor não pode ser maior que o total do pedido.'));
                        $('#second_card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Validate total of both payment methods with tolerance for floating point errors
                    var totalAmount = Math.round((firstCardAmount + secondCardAmount) * 100) / 100;
                    var roundedGrandTotal = Math.round(grandTotal * 100) / 100;

                    if (totalAmount > roundedGrandTotal + 0.01) { // Adding small tolerance (0.01)
                        this.showFirstCardError(true);
                        this.firstCardErrorMessage($t('A soma dos valores dos cartões não pode exceder o total do pedido.'));
                        $('#first_card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Validate if at least one payment method is selected
                    if (totalAmount === 0 || isNaN(totalAmount)) {
                        this.showFirstCardError(true);
                        this.firstCardErrorMessage($t('Pelo menos um método de pagamento deve ser selecionado.'));
                        $('#first_card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Handle manual form validation instead of using jQuery validation plugin
                    if ($form && $form.length) {
                        var validationResult = $form.validation() && $form.validation('isValid');

                        if (!validationResult) {
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

                var self = this;

                try {
                    if (this.validate()) {
                        this.isPlaceOrderActionAllowed(false);

                        this.getPlaceOrderDeferredObject()
                            .done(
                                function () {
                                    self.afterPlaceOrder();
                                    if (self.redirectAfterPlaceOrder) {
                                        window.location.replace(window.checkoutConfig.payment[self.getCode()].redirectUrl);
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
                        : "";
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
             * Update installments values for first card
             */
            updateInstallmentsValues: function () {
                var self = this;

                console.log('[CARDCARD] updateInstallmentsValues - INÍCIO');

                // Cancel previous request if exists
                if (self.debounceTimer !== null) {
                    clearTimeout(self.debounceTimer);
                    console.log('[CARDCARD] Cancelando timer anterior');
                }

                self.installmentsDisabled(true);
                self.isLoadingInstallments(true);

                self.debounceTimer = setTimeout(function () {
                    console.log('[CARDCARD] Timer executado - iniciando verificações');

                    var url = self.retrieveInstallmentsUrl();
                    console.log('[CARDCARD] URL de parcelas:', url);

                    if (!url || typeof fetch !== "function") {
                        console.warn('[CARDCARD] URL não encontrada ou fetch não disponível');
                        self.installmentsDisabled(false);
                        self.isLoadingInstallments(false);
                        return;
                    }

                    // Get first card amount value from form
                    var firstCardAmount = parseFloat($('#first_card_amount').val() || 0);
                    console.log('[CARDCARD] Valor do primeiro cartão:', firstCardAmount);

                    var cardType = self.selectedCardType() || self.creditCardType();
                    console.log('[CARDCARD] Tipo do cartão (inicial):', cardType);
                    console.log('[CARDCARD] selectedCardType():', self.selectedCardType());
                    console.log('[CARDCARD] creditCardType():', self.creditCardType());

                    // FORÇAR DETECÇÃO DO TIPO DO CARTÃO SE NÃO ESTIVER DEFINIDO
                    if (!cardType) {
                        console.log('[CARDCARD] Tentando detectar tipo do cartão manualmente...');
                        var cardNumber = self.vindiCreditCardNumber();
                        console.log('[CARDCARD] Número do cartão atual:', cardNumber);

                        // Se não há número no observable, tentar pegar do DOM com diferentes seletores
                        if (!cardNumber) {
                            // Tentar diferentes seletores para encontrar o campo
                            var selectors = [
                                '#' + self.getCode() + '_cc_number',
                                'input[name="payment[cc_number]"]',
                                '#vindi_vp_cardcard_cc_number',
                                '.vindi-cc-number-container input[type="text"]'
                            ];

                            for (var i = 0; i < selectors.length; i++) {
                                var $cardField = $(selectors[i]);
                                console.log('[CARDCARD] Tentando seletor:', selectors[i], 'encontrado:', $cardField.length);

                                if ($cardField.length > 0) {
                                    cardNumber = $cardField.val();
                                    console.log('[CARDCARD] Número do cartão encontrado:', cardNumber);
                                    if (cardNumber) {
                                        break;
                                    }
                                }
                            }

                            // Se ainda não encontrou, tentar buscar todos os campos de texto na seção
                            if (!cardNumber) {
                                console.log('[CARDCARD] Buscando em todos os campos de cartão...');
                                $('input[type="text"]').each(function() {
                                    var value = $(this).val();
                                    var placeholder = $(this).attr('placeholder');
                                    console.log('[CARDCARD] Campo encontrado:', {
                                        id: $(this).attr('id'),
                                        name: $(this).attr('name'),
                                        placeholder: placeholder,
                                        value: value ? value.substring(0, 4) + '...' : 'vazio'
                                    });

                                    // Verificar se é um campo de cartão
                                    if (placeholder && (placeholder.toLowerCase().includes('cartão') || placeholder.toLowerCase().includes('card')) && value && value.length >= 13) {
                                        cardNumber = value;
                                        console.log('[CARDCARD] Número do cartão encontrado por placeholder:', cardNumber.substring(0, 4) + '...');
                                        return false; // break
                                    }
                                });
                            }

                            // Se encontrou número no DOM, processar manualmente
                            if (cardNumber) {
                                console.log('[CARDCARD] Processando número do cartão manualmente:', cardNumber.substring(0, 4) + '...');
                                var result = cardNumberValidator(cardNumber);
                                console.log('[CARDCARD] Resultado manual do validator:', result);

                                if (result && result.isValid && result.card) {
                                    cardType = result.card.type;
                                    console.log('[CARDCARD] Tipo detectado manualmente:', cardType);

                                    // Atualizar os observables
                                    self.selectedCardType(cardType);
                                    self.creditCardType(cardType);
                                    self.vindiCreditCardNumber(cardNumber);

                                    // Forçar a atualização do binding
                                    setTimeout(function() {
                                        if (selectors[0] && $(selectors[0]).length > 0) {
                                            $(selectors[0]).trigger('change');
                                        }
                                    }, 100);
                                }
                            }
                        }
                    }

                    // If using saved card, get card type from payment profile
                    if (self.selectedPaymentProfile() && !cardType) {
                        console.log('[CARDCARD] Usando cartão salvo, ID do perfil:', self.selectedPaymentProfile());
                        var profiles = self.getPaymentProfiles();
                        console.log('[CARDCARD] Perfis disponíveis:', profiles);

                        var selectedProfile = profiles.find(function(profile) {
                            return profile.value == self.selectedPaymentProfile();
                        });
                        if (selectedProfile) {
                            cardType = selectedProfile.card_type;
                            console.log('[CARDCARD] Tipo do cartão do perfil:', cardType);
                        }
                    }

                    console.log('[CARDCARD] Tipo do cartão (final):', cardType);

                    // Skip if no card type selected or amount is 0
                    if (!cardType || firstCardAmount <= 0) {
                        console.log('[CARDCARD] Pulando requisição - cardType:', cardType, 'amount:', firstCardAmount);
                        self.hasInstallments(false);
                        self.installments([]);
                        self.installmentsDisabled(true);
                        self.isLoadingInstallments(false);
                        return;
                    }

                    // Map card type to correct format
                    var mappedCardType = self.mapCardType(cardType);
                    console.log('[CARDCARD] Tipo do cartão mapeado:', cardType, '->', mappedCardType);

                    var requestBody = {
                        cc_type: mappedCardType,
                        payment_link: {
                            grand_total: firstCardAmount
                        }
                    };

                    console.log('[CARDCARD] Fazendo requisição para:', url);
                    console.log('[CARDCARD] Dados da requisição:', JSON.stringify(requestBody, null, 2));

                    // Use the same structure as CardPix (working)
                    fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(requestBody)
                    }).then(function (response) {
                        console.log('[CARDCARD] Resposta recebida - Status:', response.status);
                        console.log('[CARDCARD] Resposta headers:', response.headers);

                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                        }

                        return response.json();
                    }).then(function (json) {
                        console.log('[CARDCARD] Dados recebidos:', json);
                        console.log('[CARDCARD] Tipo dos dados:', typeof json);
                        console.log('[CARDCARD] É array?', Array.isArray(json));

                        if (json && Array.isArray(json) && json.length > 0) {
                            console.log('[CARDCARD] Parcelas encontradas:', json.length);
                            self.hasInstallments(true);
                            self.installments(json);
                            self.installmentsDisabled(false);
                        } else {
                            console.log('[CARDCARD] Nenhuma parcela encontrada ou dados inválidos');
                            self.hasInstallments(false);
                            self.installments([]);
                            self.installmentsDisabled(true);
                        }
                        self.isLoadingInstallments(false);
                        console.log('[CARDCARD] updateInstallmentsValues - SUCESSO');
                    }).catch(function (error) {
                        console.error('[CARDCARD] Erro na requisição de parcelas:', error);
                        console.error('[CARDCARD] Stack trace:', error.stack);
                        self.hasInstallments(false);
                        self.installments([]);
                        self.installmentsDisabled(true);
                        self.isLoadingInstallments(false);
                    });
                }, 800);
            },

            /**
             * Update installments values for second card
             */
            updateSecondInstallmentsValues: function () {
                var self = this;

                console.log('[CARDCARD] updateSecondInstallmentsValues - INÍCIO');

                // Cancel previous request if exists
                if (self.secondDebounceTimer !== null) {
                    clearTimeout(self.secondDebounceTimer);
                    console.log('[CARDCARD] Cancelando timer anterior do segundo cartão');
                }

                self.secondInstallmentsDisabled(true);
                self.isLoadingSecondInstallments(true);

                self.secondDebounceTimer = setTimeout(function () {
                    console.log('[CARDCARD] Timer do segundo cartão executado - iniciando verificações');

                    var url = self.retrieveInstallmentsUrl();
                    console.log('[CARDCARD] URL de parcelas (segundo cartão):', url);

                    if (!url || typeof fetch !== "function") {
                        console.warn('[CARDCARD] URL não encontrada ou fetch não disponível (segundo cartão)');
                        self.secondInstallmentsDisabled(false);
                        self.isLoadingSecondInstallments(false);
                        return;
                    }

                    // Get second card amount value from form
                    var secondCardAmount = parseFloat($('#second_card_amount').val() || 0);
                    console.log('[CARDCARD] Valor do segundo cartão:', secondCardAmount);

                    var cardType = self.secondSelectedCardType() || self.secondCreditCardType();
                    console.log('[CARDCARD] Tipo do segundo cartão (inicial):', cardType);
                    console.log('[CARDCARD] secondSelectedCardType():', self.secondSelectedCardType());
                    console.log('[CARDCARD] secondCreditCardType():', self.secondCreditCardType());

                    // If using saved card, get card type from payment profile
                    if (self.secondSelectedPaymentProfile() && !cardType) {
                        console.log('[CARDCARD] Usando cartão salvo no segundo cartão, ID do perfil:', self.secondSelectedPaymentProfile());
                        var profiles = self.getPaymentProfiles();
                        console.log('[CARDCARD] Perfis disponíveis (segundo cartão):', profiles);

                        var selectedProfile = profiles.find(function(profile) {
                            return profile.value == self.secondSelectedPaymentProfile();
                        });
                        if (selectedProfile) {
                            cardType = selectedProfile.card_type;
                            console.log('[CARDCARD] Tipo do segundo cartão do perfil:', cardType);
                        }
                    }

                    console.log('[CARDCARD] Tipo do segundo cartão (final):', cardType);

                    // Skip if no card type selected or amount is 0
                    if (!cardType || secondCardAmount <= 0) {
                        console.log('[CARDCARD] Pulando requisição do segundo cartão - cardType:', cardType, 'amount:', secondCardAmount);
                        self.hasSecondInstallments(false);
                        self.secondInstallments([]);
                        self.secondInstallmentsDisabled(true);
                        self.isLoadingSecondInstallments(false);
                        return;
                    }

                    // Map card type to correct format
                    var mappedCardType = self.mapCardType(cardType);
                    console.log('[CARDCARD] Tipo do segundo cartão mapeado:', cardType, '->', mappedCardType);

                    var requestBody = {
                        cc_type: mappedCardType,
                        payment_link: {
                            grand_total: secondCardAmount
                        }
                    };

                    console.log('[CARDCARD] Fazendo requisição para segundo cartão:', url);
                    console.log('[CARDCARD] Dados da requisição (segundo cartão):', JSON.stringify(requestBody, null, 2));

                    // Use the same structure as CardPix (working)
                    fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(requestBody)
                    }).then(function (response) {
                        console.log('[CARDCARD] Resposta recebida (segundo cartão) - Status:', response.status);
                        console.log('[CARDCARD] Resposta headers (segundo cartão):', response.headers);

                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                        }

                        return response.json();
                    }).then(function (json) {
                        console.log('[CARDCARD] Dados recebidos (segundo cartão):', json);
                        console.log('[CARDCARD] Tipo dos dados (segundo cartão):', typeof json);
                        console.log('[CARDCARD] É array? (segundo cartão):', Array.isArray(json));

                        if (json && Array.isArray(json) && json.length > 0) {
                            console.log('[CARDCARD] Parcelas encontradas (segundo cartão):', json.length);
                            self.hasSecondInstallments(true);
                            self.secondInstallments(json);
                            self.secondInstallmentsDisabled(false);
                        } else {
                            console.log('[CARDCARD] Nenhuma parcela encontrada ou dados inválidos (segundo cartão)');
                            self.hasSecondInstallments(false);
                            self.secondInstallments([]);
                            self.secondInstallmentsDisabled(true);
                        }
                        self.isLoadingSecondInstallments(false);
                        console.log('[CARDCARD] updateSecondInstallmentsValues - SUCESSO');
                    }).catch(function (error) {
                        console.error('[CARDCARD] Erro na requisição de parcelas (segundo cartão):', error);
                        console.error('[CARDCARD] Stack trace (segundo cartão):', error.stack);
                        self.hasSecondInstallments(false);
                        self.secondInstallments([]);
                        self.secondInstallmentsDisabled(true);
                        self.isLoadingSecondInstallments(false);
                    });
                }, 800);
            },

            /**
             * Get payment profiles
             * @returns {Array}
             */
            getPaymentProfiles: function () {
                var paymentProfiles = [];
                var savedCards = window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.vindi_vp_cardcard &&
                    window.checkoutConfig.payment.vindi_vp_cardcard.saved_cards;

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
             * Validate form
             * @returns {boolean}
             */
            validate: function () {
                var self = this;

                try {
                    var $form = $('#' + 'form_' + this.getCode());

                    // Validate first and second card amounts
                    var firstCardAmount = parseFloat($('#first_card_amount').val() || 0);
                    var secondCardAmount = parseFloat($('#second_card_amount').val() || 0);
                    var grandTotal = this.getGrandTotal();

                    // Reset error states
                    this.showFirstCardError(false);
                    this.showSecondCardError(false);
                    $('#first_card_amount').removeClass('error');
                    $('#second_card_amount').removeClass('error');
                    this.isFormValid(true);

                    // Validate first card amount
                    if (firstCardAmount > grandTotal) {
                        this.showFirstCardError(true);
                        this.firstCardErrorMessage($t('O valor não pode ser maior que o total do pedido.'));
                        $('#first_card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Validate second card amount
                    if (secondCardAmount > grandTotal) {
                        this.showSecondCardError(true);
                        this.secondCardErrorMessage($t('O valor não pode ser maior que o total do pedido.'));
                        $('#second_card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Validate total of both payment methods with tolerance for floating point errors
                    var totalAmount = Math.round((firstCardAmount + secondCardAmount) * 100) / 100;
                    var roundedGrandTotal = Math.round(grandTotal * 100) / 100;

                    if (totalAmount > roundedGrandTotal + 0.01) { // Adding small tolerance (0.01)
                        this.showFirstCardError(true);
                        this.firstCardErrorMessage($t('A soma dos valores dos cartões não pode exceder o total do pedido.'));
                        $('#first_card_amount').addClass('error');
                        this.isFormValid(false);
                        return false;
                    }

                    // Validate if at least one payment method is selected
                    if (totalAmount === 0 || isNaN(totalAmount)) {
                        this.showFirstCardError(true);
                        this.firstCardErrorMessage($t('Pelo menos um método de pagamento deve ser selecionado.'));
                        $('#first_card_amount').addClass('error');
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

                            // CVV validation is always required for both new and saved cards
                            // Skip validation for payment profile when selected
                            if (self.selectedPaymentProfile() && ($field.attr('id') === (self.getCode() + '_cc_number') ||
                                $field.attr('id') === (self.getCode() + '_cc_owner') ||
                                $field.attr('id') === (self.getCode() + '_cc_exp_date'))) {
                                return;
                            }

                            // Skip validation for second payment profile when selected
                            if (self.secondSelectedPaymentProfile() && ($field.attr('id') === (self.getCode() + '_second_cc_number') ||
                                $field.attr('id') === (self.getCode() + '_second_cc_owner') ||
                                $field.attr('id') === (self.getCode() + '_second_cc_exp_date'))) {
                                return;
                            }

                            // Skip validation for first card fields when first card amount is 0
                            if (firstCardAmount === 0 && ($field.attr('id') === (self.getCode() + '_cc_first_installments') ||
                                $field.attr('id') === (self.getCode() + '_cc_number') ||
                                $field.attr('id') === (self.getCode() + '_cc_owner') ||
                                $field.attr('id') === (self.getCode() + '_cc_exp_date') ||
                                $field.attr('id') === (self.getCode() + '_first_cc_cid'))) {
                                return;
                            }

                            // Skip validation for second card fields when second card amount is 0
                            if (secondCardAmount === 0 && ($field.attr('id') === (self.getCode() + '_cc_second_installments') ||
                                $field.attr('id') === (self.getCode() + '_second_cc_number') ||
                                $field.attr('id') === (self.getCode() + '_second_cc_owner') ||
                                $field.attr('id') === (self.getCode() + '_second_cc_exp_date') ||
                                $field.attr('id') === (self.getCode() + '_second_cc_cid'))) {
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
                    console.error('Validation error:', e);
                    return false;
                }
            }
        });
    }
);
