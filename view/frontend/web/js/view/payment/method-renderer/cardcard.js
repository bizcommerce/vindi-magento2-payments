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
                    console.log('[CARDCARD] Atualizando gerenciador unificado para cartão 1');

                    // Usar o gerenciador unificado ao invés da função antiga
                    self.DualCardInstallmentManager.updateCard(1, {
                        type: result.card.type,
                        amount: parseFloat($('#first_card_amount').val() || 0)
                    });
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
                    console.log('[CARDCARD] Atualizando gerenciador unificado para cartão 2');

                    // Usar o gerenciador unificado ao invés da função antiga
                    self.DualCardInstallmentManager.updateCard(2, {
                        type: result.card.type,
                        amount: parseFloat($('#second_card_amount').val() || 0)
                    });
                });

                this.selectedPaymentProfile.subscribe(function (value) {
                    // Para cartão salvo, mostrar apenas campos obrigatórios (CVV)
                    // mas manter o formulário visível
                    if (value) {
                        self.showCardData(false);
                    } else {
                        self.showCardData(true);
                    }
                    // Removido: não dispara parcelamento aqui
                });

                this.secondSelectedPaymentProfile.subscribe(function (value) {
                    // Para segundo cartão salvo, mostrar apenas campos obrigatórios (CVV)
                    // mas manter o formulário visível
                    if (value) {
                        self.showCardData(false);
                    } else {
                        self.showCardData(true);
                    }
                    // Removido: não dispara parcelamento aqui
                });

                // Inicializa e adiciona o loader
                self.initializeLoader();
                self.installmentsDisabled(true);
                self.secondInstallmentsDisabled(true);

                // Remover chamadas para funções antigas - agora usamos o gerenciador unificado
                // this.updateInstallmentsValues();
                // this.updateSecondInstallmentsValues();

                // Handle first card amount change
                $(document).off('input change', '#first_card_amount');
                $(document).on('input change', '#first_card_amount', function() {
                    var grandTotal = self.getGrandTotal();
                    var $first = $('#first_card_amount');
                    var $second = $('#second_card_amount');
                    var firstVal = parseFloat($first.val().replace(',', '.') || 0);
                    if (firstVal > 0 && firstVal <= grandTotal) {
                        var remaining = Math.max(0, grandTotal - firstVal);
                        $second.val(remaining.toFixed(2));
                        $second.prop('disabled', true);
                        $first.prop('disabled', false);
                        if (remaining > 0) {
                            self.DualCardInstallmentManager.updateCard(1, { amount: firstVal });
                            self.DualCardInstallmentManager.updateCard(2, { amount: remaining });
                        }
                    } else if (!firstVal || firstVal === 0) {
                        $second.val('');
                        $second.prop('disabled', false);
                        $first.prop('disabled', false);
                        self.DualCardInstallmentManager.clearInstallments();
                    }
                });
                // Handle second card amount change
                $(document).off('input change', '#second_card_amount');
                $(document).on('input change', '#second_card_amount', function() {
                    var grandTotal = self.getGrandTotal();
                    var $second = $('#second_card_amount');
                    var $first = $('#first_card_amount');
                    var secondVal = parseFloat($second.val().replace(',', '.') || 0);
                    if (secondVal > 0 && secondVal <= grandTotal) {
                        var remaining = Math.max(0, grandTotal - secondVal);
                        $first.val(remaining.toFixed(2));
                        $first.prop('disabled', true);
                        $second.prop('disabled', false);
                        if (remaining > 0) {
                            self.DualCardInstallmentManager.updateCard(2, { amount: secondVal });
                            self.DualCardInstallmentManager.updateCard(1, { amount: remaining });
                        }
                    } else if (!secondVal || secondVal === 0) {
                        $first.val('');
                        $first.prop('disabled', false);
                        $second.prop('disabled', false);
                        self.DualCardInstallmentManager.clearInstallments();
                    }
                });

                /**
                 * Dual Card Installments Manager - Gerencia parcelas de forma unificada
                 */
                this.DualCardInstallmentManager = {
                    // Estado centralizado
                    state: {
                        card1: { type: null, amount: 0, profile: null },
                        card2: { type: null, amount: 0, profile: null },
                        isProcessing: false,
                        lastRequestHash: null
                    },

                    // Atualizar dados de um cartão
                    updateCard: function(cardIndex, data) {
                        var self = this;
                        console.log('[DUAL_CARD_MANAGER] Atualizando cartão', cardIndex, ':', data);

                        this.state[`card${cardIndex}`] = Object.assign(this.state[`card${cardIndex}`], data);

                        // Debounce para evitar múltiplas requisições
                        clearTimeout(this.debounceTimer);
                        this.debounceTimer = setTimeout(function() {
                            self.triggerInstallmentUpdate();
                        }, 800);
                    },

                    // Verificar se está pronto para buscar parcelas
                    isReadyForUpdate: function() {
                        var card1Valid = this.state.card1.amount > 0;
                        var card2Valid = this.state.card2.amount > 0;

                        return card1Valid || card2Valid;
                    },

                    // Gerar hash para cache
                    generateRequestHash: function() {
                        var data = {
                            card1: this.state.card1,
                            card2: this.state.card2
                        };
                        return JSON.stringify(data);
                    },

                    // Disparar atualização de parcelas
                    triggerInstallmentUpdate: function() {
                        var self = this;

                        if (this.state.isProcessing) {
                            console.log('[DUAL_CARD_MANAGER] Já processando, ignorando...');
                            return;
                        }

                        if (!this.isReadyForUpdate()) {
                            console.log('[DUAL_CARD_MANAGER] Não está pronto para atualizar');
                            this.clearInstallments();
                            return;
                        }

                        var requestHash = this.generateRequestHash();
                        if (requestHash === this.state.lastRequestHash) {
                            console.log('[DUAL_CARD_MANAGER] Request idêntica, usando cache');
                            return;
                        }

                        this.state.isProcessing = true;
                        this.state.lastRequestHash = requestHash;

                        console.log('[DUAL_CARD_MANAGER] Iniciando busca unificada de parcelas');
                        this.fetchUnifiedInstallments();
                    },

                    // Buscar parcelas de forma unificada
                    fetchUnifiedInstallments: function() {
                        var self = this;
                        var parentContext = this.parentContext;

                        // Ativar loading em ambos os cartões
                        parentContext.isLoadingInstallments(true);
                        parentContext.isLoadingSecondInstallments(true);
                        parentContext.installmentsDisabled(true);
                        parentContext.secondInstallmentsDisabled(true);

                        var url = parentContext.retrieveDualCardInstallmentsUrl();
                        if (!url) {
                            console.error('[DUAL_CARD_MANAGER] URL não encontrada');
                            this.handleError('URL não encontrada');
                            return;
                        }

                        // Preparar dados da requisição
                        var requestBody = this.buildRequestBody();

                        console.log('[DUAL_CARD_MANAGER] Fazendo requisição unificada:', url);
                        console.log('[DUAL_CARD_MANAGER] Dados da requisição:', JSON.stringify(requestBody, null, 2));

                        fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(requestBody)
                        }).then(function(response) {
                            console.log('[DUAL_CARD_MANAGER] Resposta recebida - Status:', response.status);

                            if (!response.ok) {
                                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                            }

                            return response.json();
                        }).then(function(data) {
                            console.log('[DUAL_CARD_MANAGER] Dados recebidos:', data);
                            self.processUnifiedResponse(data);
                        }).catch(function(error) {
                            console.error('[DUAL_CARD_MANAGER] Erro na requisição:', error);
                            self.handleError(error.message);
                        }).finally(function() {
                            self.state.isProcessing = false;
                            parentContext.isLoadingInstallments(false);
                            parentContext.isLoadingSecondInstallments(false);
                        });
                    },

                    // Construir corpo da requisição
                    buildRequestBody: function() {
                        var parentContext = this.parentContext;
                        var cards = [];

                        // Adicionar cartão 1 se valor válido
                        if (this.state.card1.amount > 0) {
                            cards.push({
                                card_index: 1,
                                cc_type: 'VI', // Usar tipo padrão (Visa) já que não é necessário
                                amount: this.state.card1.amount,
                                context: 'first_card'
                            });
                        }

                        // Adicionar cartão 2 se valor válido
                        if (this.state.card2.amount > 0) {
                            cards.push({
                                card_index: 2,
                                cc_type: 'VI', // Usar tipo padrão (Visa) já que não é necessário
                                amount: this.state.card2.amount,
                                context: 'second_card'
                            });
                        }

                        return {
                            payment_context: 'dual_card',
                            cards: cards,
                            total_order_value: parentContext.getGrandTotal()
                        };
                    },

                    // Processar resposta unificada
                    processUnifiedResponse: function(data) {
                        var parentContext = this.parentContext;

                        if (!data || !data.cards || !Array.isArray(data.cards)) {
                            console.error('[DUAL_CARD_MANAGER] Resposta inválida:', data);
                            this.handleError('Resposta inválida do servidor');
                            return;
                        }

                        // Processar cada cartão
                        data.cards.forEach(function(cardData) {
                            var cardIndex = cardData.card_index;
                            var installments = cardData.installments || [];

                            console.log('[DUAL_CARD_MANAGER] Processando cartão', cardIndex, '- Parcelas:', installments.length);

                            if (cardIndex === 1) {
                                parentContext.installments(installments);
                                parentContext.hasInstallments(installments.length > 0);
                                parentContext.installmentsDisabled(installments.length === 0);
                            } else if (cardIndex === 2) {
                                parentContext.secondInstallments(installments);
                                parentContext.hasSecondInstallments(installments.length > 0);
                                parentContext.secondInstallmentsDisabled(installments.length === 0);
                            }
                        });

                        console.log('[DUAL_CARD_MANAGER] Processamento unificado concluído com sucesso');
                    },

                    // Tratar erros
                    handleError: function(message) {
                        var parentContext = this.parentContext;

                        console.error('[DUAL_CARD_MANAGER] Erro:', message);

                        // Limpar parcelas em caso de erro
                        this.clearInstallments();
                    },

                    // Limpar parcelas
                    clearInstallments: function() {
                        var parentContext = this.parentContext;

                        parentContext.installments([]);
                        parentContext.hasInstallments(false);
                        parentContext.installmentsDisabled(true);

                        parentContext.secondInstallments([]);
                        parentContext.hasSecondInstallments(false);
                        parentContext.secondInstallmentsDisabled(true);
                    },

                    // Inicializar com contexto pai
                    init: function(parentContext) {
                        this.parentContext = parentContext;
                        console.log('[DUAL_CARD_MANAGER] Inicializado');
                    }
                };

                // Inicializar o gerenciador unificado de parcelas
                this.DualCardInstallmentManager.init(this);

                // Remover triggers antigos de parcelas em outros campos
                // Adicionar listeners apenas para os campos de valor dos cartões
                $(document).off('change keyup blur', '#first_card_amount');
                $(document).on('change keyup blur', '#first_card_amount', function() {
                    self.DualCardInstallmentManager.updateCard(1, {
                        amount: parseFloat($(this).val().replace(',', '.')) || 0
                    });
                });
                $(document).off('change keyup blur', '#second_card_amount');
                $(document).on('change keyup blur', '#second_card_amount', function() {
                    self.DualCardInstallmentManager.updateCard(2, {
                        amount: parseFloat($(this).val().replace(',', '.')) || 0
                    });
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
                console.log('[CARDCARD] getData() chamado');
                
                // Inicializar fingerprint de forma segura
                this.initializeFingerprint();

                var ccExpMonth = '';
                var ccExpYear = '';
                var ccExpDate = this.creditCardExpDate();

                var secondCcExpMonth = '';
                var secondCcExpYear = '';
                var secondCcExpDate = this.secondCreditCardExpDate();

                if (typeof ccExpDate !== "undefined" && ccExpDate !== null && ccExpDate !== '') {
                    var ccExpDateFull = ccExpDate.split('/');
                    ccExpMonth = ccExpDateFull[0] || '';
                    ccExpYear = ccExpDateFull[1] || '';
                }

                if (typeof secondCcExpDate !== "undefined" && secondCcExpDate !== null && secondCcExpDate !== '') {
                    var secondCcExpDateFull = secondCcExpDate.split('/');
                    secondCcExpMonth = secondCcExpDateFull[0] || '';
                    secondCcExpYear = secondCcExpDateFull[1] || '';
                }

                // Garantir valores seguros para anos
                var ccExpYearFinal = '';
                var secondCcExpYearFinal = '';
                
                if (ccExpYear) {
                    ccExpYearFinal = ccExpYear.length === 4 ? ccExpYear : '20' + ccExpYear;
                }
                
                if (secondCcExpYear) {
                    secondCcExpYearFinal = secondCcExpYear.length === 4 ? secondCcExpYear : '20' + secondCcExpYear;
                }

                // Obter valores dos campos de valor
                var firstCardAmount = $('#first_card_amount').val() || '0';
                var secondCardAmount = $('#second_card_amount').val() || '0';

                // Validar se há dados válidos para os cartões
                var hasFirstCard = this.vindiCreditCardNumber() && this.creditCardOwner() && ccExpMonth && ccExpYear;
                var hasSecondCard = this.secondVindiCreditCardNumber() && this.secondCreditCardOwner() && secondCcExpMonth && secondCcExpYear;

                console.log('[CARDCARD] Dados do primeiro cartão:', {
                    hasCard: hasFirstCard,
                    number: this.vindiCreditCardNumber() ? 'XXXX-' + this.vindiCreditCardNumber().slice(-4) : 'vazio',
                    owner: this.creditCardOwner(),
                    amount: firstCardAmount
                });

                console.log('[CARDCARD] Dados do segundo cartão:', {
                    hasCard: hasSecondCard,
                    number: this.secondVindiCreditCardNumber() ? 'XXXX-' + this.secondVindiCreditCardNumber().slice(-4) : 'vazio',
                    owner: this.secondCreditCardOwner(),
                    amount: secondCardAmount
                });

                var data = {
                    'method': this.item.method,
                    'additional_data': {
                        'payment_profile': this.selectedPaymentProfile() || '',
                        'second_payment_profile': this.secondSelectedPaymentProfile() || '',
                        'taxvat': this.taxvat() || '',
                        'cc_cid': this.creditCardVerificationNumber() || '',
                        'cc_cid_2': this.secondCreditCardVerificationNumber() || '',
                        'cc_type': this.mapCardType(this.creditCardType()) || '',
                        'cc_type_2': this.mapCardType(this.secondCreditCardType()) || '',
                        'cc_exp_month': ccExpMonth,
                        'cc_exp_month_2': secondCcExpMonth,
                        'cc_exp_year': ccExpYearFinal,
                        'cc_exp_year_2': secondCcExpYearFinal,
                        'cc_number': this.vindiCreditCardNumber() || '',
                        'cc_number_2': this.secondVindiCreditCardNumber() || '',
                        'cc_owner': this.creditCardOwner() || '',
                        'cc_owner_2': this.secondCreditCardOwner() || '',
                        'installments': this.creditCardInstallments() || '1',
                        'installments_2': this.secondCreditCardInstallments() || '1',
                        'amount_card1': firstCardAmount,
                        'amount_card2': secondCardAmount,
                        'save_card': this.saveCard() ? 1 : 0,
                        'save_card_2': this.secondSaveCard() ? 1 : 0,
                        'fingerprint': this.getFingerprint() || '',
                        'fingerprint_2': this.getFingerprint() || ''
                    }
                };

                console.log('[CARDCARD] Dados finais a serem enviados:', JSON.stringify(data, null, 2));
                return data;
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
                    console.log('[CARDCARD] Iniciando validação do formulário');

                    var $form = $('#' + 'form_' + this.getCode());

                    // Validate card amounts
                    var firstCardAmount = parseFloat($('#first_card_amount').val() || 0);
                    var secondCardAmount = parseFloat($('#second_card_amount').val() || 0);
                    var grandTotal = this.getGrandTotal();

                    console.log('[CARDCARD] Valores:', {
                        firstCard: firstCardAmount,
                        secondCard: secondCardAmount,
                        total: grandTotal
                    });

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

                    // Validação dos campos obrigatórios do primeiro cartão
                    if (firstCardAmount > 0) {
                        // Se cartão salvo estiver selecionado, só validar CVV
                        if (this.selectedPaymentProfile()) {
                            if (!this.creditCardVerificationNumber() || this.creditCardVerificationNumber().length < 3) {
                                console.error('[CARDCARD] CVV do primeiro cartão salvo é obrigatório');
                                this.showFirstCardError(true);
                                this.firstCardErrorMessage($t('CVV do primeiro cartão é obrigatório.'));
                                return false;
                            }
                        } else {
                            // Validar campos de cartão novo
                            if (!this.vindiCreditCardNumber() || this.vindiCreditCardNumber().length < 13) {
                                console.error('[CARDCARD] Número do primeiro cartão inválido');
                                this.showFirstCardError(true);
                                this.firstCardErrorMessage($t('Número do primeiro cartão é obrigatório.'));
                                return false;
                            }
                            
                            if (!this.creditCardOwner() || this.creditCardOwner().trim() === '') {
                                console.error('[CARDCARD] Nome do portador do primeiro cartão é obrigatório');
                                this.showFirstCardError(true);
                                this.firstCardErrorMessage($t('Nome do portador do primeiro cartão é obrigatório.'));
                                return false;
                            }
                            
                            if (!this.creditCardExpDate() || this.creditCardExpDate().length < 5) {
                                console.error('[CARDCARD] Data de vencimento do primeiro cartão é obrigatória');
                                this.showFirstCardError(true);
                                this.firstCardErrorMessage($t('Data de vencimento do primeiro cartão é obrigatória.'));
                                return false;
                            }
                            
                            if (!this.creditCardVerificationNumber() || this.creditCardVerificationNumber().length < 3) {
                                console.error('[CARDCARD] CVV do primeiro cartão é obrigatório');
                                this.showFirstCardError(true);
                                this.firstCardErrorMessage($t('CVV do primeiro cartão é obrigatório.'));
                                return false;
                            }
                        }
                    }

                    // Validação dos campos obrigatórios do segundo cartão
                    if (secondCardAmount > 0) {
                        // Se cartão salvo estiver selecionado, só validar CVV
                        if (this.secondSelectedPaymentProfile()) {
                            if (!this.secondCreditCardVerificationNumber() || this.secondCreditCardVerificationNumber().length < 3) {
                                console.error('[CARDCARD] CVV do segundo cartão salvo é obrigatório');
                                this.showSecondCardError(true);
                                this.secondCardErrorMessage($t('CVV do segundo cartão é obrigatório.'));
                                return false;
                            }
                        } else {
                            // Validar campos de cartão novo
                            if (!this.secondVindiCreditCardNumber() || this.secondVindiCreditCardNumber().length < 13) {
                                console.error('[CARDCARD] Número do segundo cartão inválido');
                                this.showSecondCardError(true);
                                this.secondCardErrorMessage($t('Número do segundo cartão é obrigatório.'));
                                return false;
                            }
                            
                            if (!this.secondCreditCardOwner() || this.secondCreditCardOwner().trim() === '') {
                                console.error('[CARDCARD] Nome do portador do segundo cartão é obrigatório');
                                this.showSecondCardError(true);
                                this.secondCardErrorMessage($t('Nome do portador do segundo cartão é obrigatório.'));
                                return false;
                            }
                            
                            if (!this.secondCreditCardExpDate() || this.secondCreditCardExpDate().length < 5) {
                                console.error('[CARDCARD] Data de vencimento do segundo cartão é obrigatória');
                                this.showSecondCardError(true);
                                this.secondCardErrorMessage($t('Data de vencimento do segundo cartão é obrigatória.'));
                                return false;
                            }
                            
                            if (!this.secondCreditCardVerificationNumber() || this.secondCreditCardVerificationNumber().length < 3) {
                                console.error('[CARDCARD] CVV do segundo cartão é obrigatório');
                                this.showSecondCardError(true);
                                this.secondCardErrorMessage($t('CVV do segundo cartão é obrigatório.'));
                                return false;
                            }
                        }
                    }

                    // Validação manual do CPF/CNPJ usando método local
                    if (!this.validateTaxvat()) {
                        console.error('[CARDCARD] Validação de CPF/CNPJ falhou');
                        return false;
                    }

                    console.log('[CARDCARD] Validação concluída com sucesso');
                    return true;
                    
                } catch (e) {
                    console.error('[CARDCARD] Erro durante validação:', e);
                    return false; // Em caso de erro na validação, não permite continuar
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
             * Retrieve dual card installments URL
             * @returns {string}
             */
            retrieveDualCardInstallmentsUrl: function () {
                try {
                    return window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.ccform &&
                    window.checkoutConfig.payment.ccform.urls &&
                    window.checkoutConfig.payment.ccform.urls[this.getCode()] &&
                    window.checkoutConfig.payment.ccform.urls[this.getCode()].retrieve_dual_card_installments
                        ? window.checkoutConfig.payment.ccform.urls[this.getCode()].retrieve_dual_card_installments
                        : "";
                } catch (e) {
                    console.log('Dual Card Installments URL not defined');
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
                    console.log('[CARDCARD] Cancelando timer anterior do segundo cart��o');
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
             * Trigger unified installment update - método principal para disparar o gerenciador
             */
            triggerUnifiedInstallmentUpdate: function() {
                console.log('[CARDCARD] triggerUnifiedInstallmentUpdate chamado');

                var firstCardAmount = parseFloat($('#first_card_amount').val() || 0);
                var secondCardAmount = parseFloat($('#second_card_amount').val() || 0);

                console.log('[CARDCARD] Valores - Cartão 1:', firstCardAmount, 'Cartão 2:', secondCardAmount);

                // Debug: verificar se o DualCardInstallmentManager está disponível
                if (!this.DualCardInstallmentManager) {
                    console.error('[CARDCARD] DualCardInstallmentManager não está disponível!');
                    return;
                }

                // Atualizar o gerenciador apenas com os valores (sem tipos)
                if (firstCardAmount > 0) {
                    console.log('[CARDCARD] Atualizando cartão 1 com valor:', firstCardAmount);
                    this.DualCardInstallmentManager.updateCard(1, {
                        type: 'VI', // Tipo padrão, não é relevante para parcelas
                        amount: firstCardAmount
                    });
                }

                if (secondCardAmount > 0) {
                    console.log('[CARDCARD] Atualizando cartão 2 com valor:', secondCardAmount);
                    this.DualCardInstallmentManager.updateCard(2, {
                        type: 'VI', // Tipo padrão, não é relevante para parcelas
                        amount: secondCardAmount
                    });
                }

                // Se nenhum cartão tem valores válidos, limpar tudo
                if (firstCardAmount === 0 && secondCardAmount === 0) {
                    console.log('[CARDCARD] Nenhum valor válido, limpando parcelas');
                    this.clearAllInstallments();
                }
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
             * Obter tipo do cartão (com fallbacks)
             */
            getCardType: function(cardIndex) {
                if (cardIndex === 1) {
                    // Primeiro cartão
                    var cardType = this.selectedCardType() || this.creditCardType();

                    // Se usando cartão salvo
                    if (this.selectedPaymentProfile() && !cardType) {
                        var profiles = this.getPaymentProfiles();
                        var selectedProfile = profiles.find(function(profile) {
                            return profile.value == this.selectedPaymentProfile();
                        }.bind(this));
                        if (selectedProfile) {
                            cardType = selectedProfile.card_type;
                        }
                    }

                    return cardType;
                } else if (cardIndex === 2) {
                    // Segundo cartão
                    var cardType = this.secondSelectedCardType() || this.secondCreditCardType();

                    // Se usando cartão salvo
                    if (this.secondSelectedPaymentProfile() && !cardType) {
                        var profiles = this.getPaymentProfiles();
                        var selectedProfile = profiles.find(function(profile) {
                            return profile.value == this.secondSelectedPaymentProfile();
                        }.bind(this));
                        if (selectedProfile) {
                            cardType = selectedProfile.card_type;
                        }
                    }

                    return cardType;
                }

                return null;
            },

            /**
             * Limpar todas as parcelas
             */
            clearAllInstallments: function() {
                console.log('[CARDCARD] Limpando todas as parcelas');

                this.installments([]);
                this.hasInstallments(false);
                this.installmentsDisabled(true);
                this.creditCardInstallments('');

                this.secondInstallments([]);
                this.hasSecondInstallments(false);
                this.secondInstallmentsDisabled(true);
                this.secondCreditCardInstallments('');
            },

            /**
             * Validar CPF/CNPJ manualmente
             * @returns {boolean}
             */
            validateTaxvat: function() {
                var taxvatField = $('#' + this.getCode() + '_taxvat');
                if (taxvatField.length === 0) {
                    return true; // Se não existe o campo, considera válido
                }

                var taxvatValue = taxvatField.val();
                if (!taxvatValue || taxvatValue.trim() === '') {
                    console.error('CPF/CNPJ é obrigatório');
                    return false;
                }

                // Remove formatação
                var cleanTaxvat = taxvatValue.replace(/[^\d]/g, '');
                
                // Validação básica de comprimento
                if (cleanTaxvat.length !== 11 && cleanTaxvat.length !== 14) {
                    console.error('CPF/CNPJ deve ter 11 ou 14 dígitos');
                    return false;
                }

                // Validação de CPF (11 dígitos)
                if (cleanTaxvat.length === 11) {
                    return this.validateCpfLocal(cleanTaxvat);
                }

                // Validação de CNPJ (14 dígitos)
                if (cleanTaxvat.length === 14) {
                    return this.validateCnpjLocal(cleanTaxvat);
                }

                return false;
            },

            /**
             * Validar CPF localmente
             * @param {string} cpf
             * @returns {boolean}
             */
            validateCpfLocal: function(cpf) {
                return cpf.length === 11;
            },

            validateCnpjLocal: function(cnpj) {
                return cnpj.length === 14;
            },

            initializeMasks: function() {
                var self = this;
                $('#' + self.getCode() + '_cc_exp_date').mask('00/00');
                $('#' + self.getCode() + '_second_cc_exp_date').mask('00/00');
            },

            afterRender: function() {
                var self = this;
                self.initializeMasks();
            }
        });
    }
);

