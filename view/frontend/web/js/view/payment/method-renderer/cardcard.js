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
                    window.checkoutConfig.payment.vindi_vp_cardpix &&
                    window.checkoutConfig.payment.vindi_vp_cardpix.customer_taxvat
                ) ? window.checkoutConfig.payment.vindi_vp_cardpix.customer_taxvat.replace(/[^0-9]/g, "") : "",
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
                    if (!value) {
                        return;
                    }
                    var result = cardNumberValidator(value);
                    if (!result || !result.isValid) {
                        return;
                    }
                    if (result.card !== null) {
                        self.selectedCardType(result.card.type);
                    }
                    creditCardData.vindiCreditCardNumber = value;
                    self.creditCardType(result.card.type);
                    self.updateInstallmentsValues();
                });

                this.secondVindiCreditCardNumber.subscribe(function (value) {
                    if (!value) {
                        return;
                    }
                    var result = cardNumberValidator(value);
                    if (!result || !result.isValid) {
                        return;
                    }
                    if (result.card !== null) {
                        self.secondSelectedCardType(result.card.type);
                    }
                    self.secondCreditCardType(result.card.type);
                    self.updateSecondInstallmentsValues();
                });

                this.selectedPaymentProfile.subscribe(function (value) {
                    if (value) {
                        self.showCardData(false);
                    } else {
                        self.showCardData(true);
                    }
                    self.updateInstallmentsValues();
                });

                this.secondSelectedPaymentProfile.subscribe(function (value) {
                    if (value) {
                        self.showCardData(false);
                    } else {
                        self.showCardData(true);
                    }
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
                        'second_cc_cid': this.secondCreditCardVerificationNumber(),
                        'cc_type': this.mapCardType(this.creditCardType()),
                        'second_cc_type': this.mapCardType(this.secondCreditCardType()),
                        'cc_exp_month': ccExpMonth,
                        'second_cc_exp_month': secondCcExpMonth,
                        'cc_exp_year': ccExpYear && ccExpYear.length === 4 ? ccExpYear : '20' + ccExpYear,
                        'second_cc_exp_year': secondCcExpYear && secondCcExpYear.length === 4 ? secondCcExpYear : '20' + secondCcExpYear,
                        'cc_number': this.vindiCreditCardNumber(),
                        'second_cc_number': this.secondVindiCreditCardNumber(),
                        'cc_owner': this.creditCardOwner(),
                        'second_cc_owner': this.secondCreditCardOwner(),
                        'installments': this.creditCardInstallments(),
                        'second_installments': this.secondCreditCardInstallments(),
                        'first_card_amount': $('#first_card_amount').val(),
                        'second_card_amount': $('#second_card_amount').val(),
                        'save_card': this.saveCard() ? 1 : 0,
                        'second_save_card': this.secondSaveCard() ? 1 : 0,
                        'fingerprint': (window.yapay && window.yapay.FingerPrint) ? window.yapay.FingerPrint().getFingerPrint() : ''
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

                    // Get first card amount value from form
                    var firstCardAmount = parseFloat($('#first_card_amount').val() || 0);

                    fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            card_type: self.creditCardType(),
                            amount: firstCardAmount
                        })
                    }).then(function (response) {
                        return response.json();
                    }).then(function (json) {
                        self.hasInstallments(true);
                        self.installments(json);
                        self.installmentsDisabled(false);
                        self.isLoadingInstallments(false);
                    }).catch(function () {
                        self.installmentsDisabled(false);
                        self.isLoadingInstallments(false);
                    });
                }, 500);
            },

            /**
             * Update installments values for second card
             */
            updateSecondInstallmentsValues: function () {
                var self = this;
                self.secondInstallmentsDisabled(true);

                // Mostrar o loader
                self.isLoadingSecondInstallments(true);

                if (self.secondDebounceTimer !== null) {
                    clearTimeout(self.secondDebounceTimer);
                }

                self.secondDebounceTimer = setTimeout(function () {
                    var url = self.retrieveInstallmentsUrl();
                    if (!url || typeof fetch !== "function") {
                        self.secondInstallmentsDisabled(false);
                        self.isLoadingSecondInstallments(false);
                        return;
                    }

                    // Get second card amount value from form
                    var secondCardAmount = parseFloat($('#second_card_amount').val() || 0);

                    fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            card_type: self.secondCreditCardType(),
                            amount: secondCardAmount
                        })
                    }).then(function (response) {
                        return response.json();
                    }).then(function (json) {
                        self.hasSecondInstallments(true);
                        self.secondInstallments(json);
                        self.secondInstallmentsDisabled(false);
                        self.isLoadingSecondInstallments(false);
                    }).catch(function () {
                        self.secondInstallmentsDisabled(false);
                        self.isLoadingSecondInstallments(false);
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
