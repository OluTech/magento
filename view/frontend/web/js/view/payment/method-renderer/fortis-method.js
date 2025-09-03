/*browser:true*/
/*global define*/
define(
  [
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/action/place-order',
    'Magento_Checkout/js/action/select-payment-method',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/checkout-data',
    'Magento_Checkout/js/model/payment/additional-validators',
    'mage/url',
    'Magento_Payment/js/view/payment/cc-form',
    'Magento_Vault/js/view/payment/vault-enabler',
    'Fortispay_Fortis/js/view/payment/fortis-iframe', // <-- Ensure iframe script is loaded
    'Magento_Checkout/js/model/quote'
  ],
  function ($,
    Component,
    placeOrderAction,
    selectPaymentMethodAction,
    customer,
    checkoutData,
    additionalValidators,
    _urlBuilder,
    Component2,
    VaultEnabler,
    FortisIframe,
    quote
  ) {
    'use strict';
    let fortisPaymentType;
    let fortisSavedCard = undefined;
    let surchargeData;
    let spinner = null;
    let ticketIntentionPayForm = null;

        return Component.extend({
            defaults: {
                template: 'Fortispay_Fortis/payment/fortis'
            },
            initialize: function () {
                this._super();

                window.FortisPayment = window.FortisPayment || {};
                window.FortisPayment.afterPlaceOrder = this.afterPlaceOrder.bind(this);

                if (this.isChecked()) {
                    this.onPaymentMethodSelected();
                }

                this.isChecked.subscribe(function (isChecked) {
                    if (isChecked) {
                        this.onPaymentMethodSelected();
                    }
                }.bind(this));

                var self = this;
                quote.totals.subscribe(function (totals) {
                    if (self.isChecked()) {
                        setTimeout(function () {
                            var sel = document.getElementById('fortis-saved_cards');
                            if (sel && sel.value && sel.value !== 'new' && sel.value !== 'new-save') {
                                try {
                                    self.displaySurcharge(null, { target: sel });
                            } catch (e) {
                                console.error('[Fortis] retrigger displaySurcharge failed', e);
                            }
                        }
                    }, 100);
                }});
                return this;
            },
            onPaymentMethodSelected: function () {
                this.checkTicketIntentionLoad();
            },
            getData: function () {
                fortisPaymentType = $('input[name=fortis-payment-type]:checked').val();
                if (typeof fortisSavedCard === 'undefined') {
                    fortisSavedCard = $('#fortis-saved_cards').val();
                }

                let fortisVault;
                if ($('#fortis-vault-method').prop('checked') === true) {
                    fortisVault = 1;
                } else {
                    if (fortisSavedCard !== undefined) {
                        fortisVault = fortisSavedCard;
                    } else {
                        fortisVault = 0;
                    }
                }
                if (null === fortisPaymentType || typeof fortisPaymentType == 'undefined') {
                    fortisPaymentType = 0;
                }

                window.fortisVault = fortisVault;

                return {
                    'method': this.item.method,
                    'additional_data': {
                        'fortis-vault-method': fortisVault,
                        'fortis-payment-type': fortisPaymentType,
                        'fortis-surcharge-data': surchargeData ? JSON.stringify(surchargeData) : null,
                    }
                };
            },
            isFortisVaultEnabled: function () {
                return window.checkoutConfig.payment.fortis.isVault;
            },
            useFortisVaultEnabled: function () {
                return true;
            },
            isFramed: function () {
                return typeof window.checkoutConfig.payment[this.getCode()] !== 'undefined' &&
                    window.checkoutConfig.payment[this.getCode()].isCheckoutIframe;
            },
            frameSingleView: function () {
                return typeof window.checkoutConfig.payment[this.getCode()] !== 'undefined' &&
                    window.checkoutConfig.payment[this.getCode()].isSingleView;
            },
            getPaymentTypesList: function () {
                return window.checkoutConfig.payment.fortis.paymentTypeList;
            },
            getFortisSavedCardList: function () {
                return window.checkoutConfig.payment.fortis.saved_card_data;
            },
            checkFortisSavedCard: function () {
                return window.checkoutConfig.payment.fortis.card_count;
            },
            isPaymentTypes: function () {
                const paymentTypes = window.checkoutConfig.payment.fortis.paymentTypes;
                if ('null' != paymentTypes) {
                    return true;
                }
                return false;
            },
            placeOrder: function (data, event) {
                if (event) {
                    event.preventDefault();
                }
                var self = this;
                if (!this.isTokenised() && window.FortisTicketIntention && window.FortisTicketIntention.elements) {
                    window.FortisTicketIntention.checkoutContext = self;
                    this.getData();
                    window.FortisTicketIntention.elements.submit();
                    return true;
                } else if (this.isTokenised() && window.FortisTicketIntention && window.FortisTicketIntention.elements) {
                    this.processTokenizedPayment();
                    return true;
                }
                var placeOrder,
                    emailValidationResult = customer.isLoggedIn(),
                    loginFormSelector = 'form[data-role=email-with-possible-login]';
                if (!customer.isLoggedIn()) {
                    $(loginFormSelector).validation();
                    emailValidationResult = Boolean($(loginFormSelector + ' input[name=username]').valid());
                }

                if (emailValidationResult && this.validate() && additionalValidators.validate()) {
                    this.isPlaceOrderActionAllowed(false);
                    placeOrder = placeOrderAction(this.getData(), false, this.messageContainer);
                    $.when(placeOrder).fail(function () {
                        self.isPlaceOrderActionAllowed(true);
                    }).done(function () {
                        if (fortisSavedCard !== undefined && !(fortisSavedCard === 'new' || fortisSavedCard === 'new-save')) {
                            window.location.replace(_urlBuilder.build(window.checkoutConfig.payment.fortis.redirectUrl));
                        } else if (window.FortisTicketIntention && window.FortisTicketIntention.elements) {
                            window.FortisTicketIntention.checkoutContext = self; // Ensure self is available
                            window.FortisTicketIntention.elements.submit();
                        } else if (!window.FortisTicketIntention.elements) {
                            self.afterPlaceOrder();
                        }
                    });
                    return true;
                }
                return false;
            },
            getCode: function () {
                return 'fortis';
            },
            selectPaymentMethod: function () {
                selectPaymentMethodAction(this.getData());
                checkoutData.setSelectedPaymentMethod(this.item.method);

                this.checkTicketIntentionLoad();

                return true;
            },
            checkTicketIntentionLoad: function() {
                const form = document.getElementById('fortis_payment420');
                if (!form) return;

                let observer;

                const tryAppend = () => {
                    const container = document.getElementById('fortis-payment-form-container');
                    if (container) {
                        container.appendChild(form);
                        form.style.display = 'block';
                        if (observer) {
                            observer.disconnect();
                        }
                    }
                };

                tryAppend();

                observer = new MutationObserver(tryAppend);
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            },
            afterPlaceOrder: function () {
                let $frame = $('#fortis-framed-2567');
                $frame.hide();
                $frame.parent().next().hide();

                if (!this.isFramed()) {
                    window.location.replace(_urlBuilder.build(window.checkoutConfig.payment.fortis.redirectUrl));
                } else if (this.isTokenised()) {
                    window.location.replace(_urlBuilder.build(window.checkoutConfig.payment.fortis.redirectUrl));
                } else if (ticketIntentionPayForm) {
                    window.location.replace(_urlBuilder.build(window.checkoutConfig.payment.fortis.returnUrl));
                } else {
                    let url = 'fortis/iframe/classic';
                    $frame.trigger('processStart');
                    $.post(
                        _urlBuilder.build(url),
                        {}
                    ).done(function (result) {
                        if (result.success === false) {
                            $frame.html('<h1>'+result.reason+'</h1>');
                        } else {
                            $frame.html(result);
                        }

                        $frame.trigger('processStop');
                        $frame[0].scrollIntoView();
                        $frame.show();

                        if (typeof window.fortisGenerateIFrame === 'function') {
                            window.fortisGenerateIFrame();
                        }

                    }).fail(function (response) {
                        $frame.trigger('processStop');
                    });
                }
            },
            isTokenised: function () {
              return (fortisSavedCard !== undefined && !(fortisSavedCard === 'new' || fortisSavedCard === 'new-save'));
            },
            getInstructions: function () {
                return window.checkoutConfig.payment.instructions[this.item.method];
            },
            isAvailable: function () {
                return quote.totals().grand_total <= 0;
            },
            getPaymentAcceptanceMarkHref: function () {
                return window.checkoutConfig.payment.fortis.paymentAcceptanceMarkHref;
            },
            getPaymentAcceptanceMarkSrc: function () {
                return window.checkoutConfig.payment.fortis.paymentAcceptanceMarkSrc;
            },
            achIsEnabled: function () {
                return window.checkoutConfig.payment.fortis.achIsEnabled;
            },
            displaySurcharge: function (data, event) {
                var selectElement = event.target;
                var selectedValue = selectElement.value;
                var surchargeDisclaimer = jQuery('#surcharge-disclaimer');
                var documentedSurchargeDisclaimer = document.getElementById('surcharge-disclaimer');
                var placeOrderBtn = jQuery('.action.primary.checkout');
                var ticketIntentionForm = jQuery('#fortis_payment420');

                var selectedOption = selectElement.options[selectElement.selectedIndex];
                var cardType = selectedOption.getAttribute('data-card-type');

                surchargeDisclaimer.html('');
                placeOrderBtn.prop('disabled', true);

                if (selectedValue !== 'new' && selectedValue !== 'new-save' && cardType !== 'ach') {
                    if (ticketIntentionForm) {
                        ticketIntentionForm.hide();
                    }
                    this.generateSpinner(documentedSurchargeDisclaimer);

                    this.calculateSurcharge(selectedValue)
                        .done(function(response) {
                            response = JSON.parse(response.surchargeData);
                            fortisSavedCard = selectedValue;
                            if (response.data && response.data.surcharge_amount) {
                                surchargeData = response.data;
                                surchargeDisclaimer.html(`<br><p>Subtotal: $${(surchargeData.subtotal_amount / 100).toFixed(2)}
<br>Tax: $${(surchargeData.tax_amount / 100).toFixed(2)}
<br>Surcharge Amount: $${(surchargeData.surcharge_amount / 100).toFixed(2)}
<br><strong>Total: $${(surchargeData.transaction_amount / 100).toFixed(2)}</strong></p>
<p>${window.checkoutConfig.payment.fortis.surchargeDisclaimer}</p>`);
                            } else {
                                surchargeData = null;
                                surchargeDisclaimer.html('');
                            }
                        })
                        .fail(function(xhr, status, error) {
                            console.error('Error calculating surcharge:', error);
                            surchargeDisclaimer.html('');
                        })
                        .always(function() {
                            placeOrderBtn.prop('disabled', false);
                        });
                } else {
                    surchargeDisclaimer.html('');
                    if (ticketIntentionForm) {
                        ticketIntentionForm.show();
                    }
                    fortisSavedCard = undefined;
                    placeOrderBtn.prop('disabled', false);
                }
            },
            getPlaceOrderBtn: function () {
                return window.checkoutConfig.payment.fortis.placeOrderBtnText;
            },
            calculateSurcharge: function (publicHash) {
                // Returns a Promise
                return $.ajax({
                    url: '/fortis/api/calculatesurcharge',
                    method: 'GET',
                    data: { public_hash: publicHash }
                });
            },

            processTokenizedPayment: function () {
                var self = this;
                var placeOrderBtn = jQuery('.action.primary.checkout');

                placeOrderBtn.prop('disabled', true);
                self.isPlaceOrderActionAllowed(false);

                var requestData = {
                    public_hash: fortisSavedCard
                };

                if (surchargeData) {
                    requestData.surcharge_data = surchargeData;
                }

                $.ajax({
                    url: '/fortis/api/processtokenizedpayment',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(requestData),
                    beforeSend: function() {
                        const ticketErrorDiv = document.getElementById('ticketError');
                        if (ticketErrorDiv) {
                            ticketErrorDiv.remove();
                        }

                        var container = document.getElementById('surcharge-disclaimer');
                        if (container) {
                            self.generateSpinner(container);
                        }
                    }
                })
                .done(function(response) {
                    if (response.success && response.reason_code_id === 1000) {

                        var placeOrder = placeOrderAction(self.getData(), false, self.messageContainer);
                        $.when(placeOrder).done(function() {
                            var payload = {
                                transactionId: response.transaction_id,
                                surchargeData: surchargeData,
                                '@action': 'ticket'
                            };

                            $.ajax({
                                url: window.checkoutConfig.payment.fortis.returnUrl,
                                method: 'POST',
                                contentType: 'application/json',
                                data: JSON.stringify(payload)
                            })
                            .done(function(returnResponse) {
                                if (returnResponse.redirectTo) {
                                    window.location.href = returnResponse.redirectTo;
                                } else {
                                    console.error('Redirect URL not provided in response');
                                }
                            })
                            .fail(function(xhr, status, error) {
                                console.error('Error calling returnUrl:', error);
                            })
                            .always(function() {
                                var spinner = document.getElementById('fortis-spinner');
                                if (spinner) {
                                    spinner.remove();
                                }
                                placeOrderBtn.prop('disabled', false);
                                self.isPlaceOrderActionAllowed(true);
                            });
                        }).fail(function() {
                            self.handleTokenizedPaymentError('Failed to place order in Magento');
                            var spinner = document.getElementById('fortis-spinner');
                            if (spinner) {
                                spinner.remove();
                            }
                            placeOrderBtn.prop('disabled', false);
                            self.isPlaceOrderActionAllowed(true);
                        });
                    } else {
                        self.handleTokenizedPaymentError(response.error || 'Payment failed. Please try again.');
                        var spinner = document.getElementById('fortis-spinner');
                        if (spinner) {
                            spinner.remove();
                        }
                        placeOrderBtn.prop('disabled', false);
                        self.isPlaceOrderActionAllowed(true);
                    }
                })
                .fail(function(xhr, status, error) {
                    var errorMessage = 'Payment processing failed';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMessage = xhr.responseJSON.error;
                    }
                    self.handleTokenizedPaymentError(errorMessage);
                    var spinner = document.getElementById('fortis-spinner');
                    if (spinner) {
                        spinner.remove();
                    }
                    placeOrderBtn.prop('disabled', false);
                    self.isPlaceOrderActionAllowed(true);
                });
            },

            handleTokenizedPaymentError: function (errorMessage) {
                console.error('Tokenized payment error:', errorMessage);

                this.messageContainer.addErrorMessage({
                    message: errorMessage
                });

                const container = document.getElementById('surcharge-disclaimer');
                const errorDiv = document.createElement('div');
                errorDiv.id = 'ticketError';
                errorDiv.style.color = 'red';
                errorDiv.style.marginBottom = '10px';
                errorDiv.style.marginTop = '10px';
                errorDiv.innerHTML = errorMessage;
                container.prepend(errorDiv);
            },

            generateSpinner: function(container) {
              spinner = document.createElement('div');
              spinner.id = 'fortis-spinner';
              spinner.style.cssText = 'display: flex; justify-content: center; align-items: center; height: 100%; margin: 20px 0;';
              spinner.innerHTML = '<div style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite;"></div>';
              container.appendChild(spinner);

              const styleSheet = document.createElement('style');
              styleSheet.innerText = '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
              document.head.appendChild(styleSheet);
          }
        });
  }
);
