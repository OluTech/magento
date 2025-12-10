(function() {
    'use strict';

    let elements = null;
    let lastResult = null;
    let spinner = null;
    let ticketIntentionData = null;

    function isFortisPaymentSelected() {
        const fortisRadio = document.querySelector('input[name="payment[method]"][value="fortis"]');
        return fortisRadio && fortisRadio.checked;
    }

    function getFortisModuleName(env) {
        return env === 'production' ? 'fortis-commerce-prod' : 'fortis-commerce-sandbox';
    }

    function generateTicketIntentionPayForm() {
        const config = window.checkoutConfig?.payment?.fortis;
        if (!config || !config.main_options || !config.ticketIntentionToken) {
            return;
        }
        ticketIntentionData = config;
        var moduleName = getFortisModuleName(config.main_options.environment);

        require([moduleName, 'jquery', 'Magento_Checkout/js/action/place-order'], function (
            Commerce,
            $,
            placeOrderAction
        ) {
            // Remove previous listeners if elements exists
            if (elements && typeof elements.off === 'function') {
                elements.off('done');
                elements.off('error');
                elements.off('submitted');
            }
            elementsCreate(ticketIntentionData.ticketIntentionToken, Commerce);

            window.FortisTicketIntention.elements = elements;

            elements.on('done', async (result) => {
                lastResult = result;
                const response = await fetch(ticketIntentionData.calculateSurchargeUrl + '?ticket_id=' + result.data.id, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                });

                if (response.ok) {
                    const responseData = await response.json();
                    const surchargeData = JSON.parse(responseData.surchargeData).data;
                    const ticketSurchargeDisclaimer = jQuery('#ticket-surcharge-disclaimer');

                    if (surchargeData !== null && surchargeData.surcharge_amount) {
                        document.getElementById('fortis_payment420').style.display = 'none';
                        ticketSurchargeDisclaimer.html(`
                            <br>
                            <p>
                                Subtotal: $${(surchargeData.subtotal_amount / 100).toFixed(2)}<br>
                                Tax: $${(surchargeData.tax_amount / 100).toFixed(2)}<br>
                                Surcharge Amount: $${(surchargeData.surcharge_amount / 100).toFixed(2)}<br>
                                <strong>Total: $${(surchargeData.transaction_amount / 100).toFixed(2)}</strong>
                            </p>
                            <p>${ticketIntentionData.surchargeDisclaimer}</p>
                            <button id="cancel-order-btn" type="button">Cancel</button>
                            <button id="continue-order-btn" type="button">Continue</button>
                        `);

                        document.getElementById('cancel-order-btn').addEventListener('click', function() {
                            cancelOrder(null);
                        });
                        document.getElementById('continue-order-btn').addEventListener('click', (event) => {
                            event.preventDefault();
                            continueOrder(lastResult, window.FortisTicketIntention.checkoutContext, surchargeData, placeOrderAction);
                        });
                    } else {
                         await continueOrder(lastResult, window.FortisTicketIntention.checkoutContext, null, placeOrderAction);
                    }
                } else {
                    console.error('Request failed with status:', response.status);
                }
            });

            elements.on('error', function (event) {
                let $frame = jQuery('#fortis-framed-2567');
                $frame.show();
                $frame.parent().next().show();
            });

            elements.on('submitted', async () => {
                let $frame = jQuery('#fortis-framed-2567');
                $frame.hide();
                $frame.parent().next().hide();

                const ticketErrorDiv = document.getElementById('ticketError');
                if (ticketErrorDiv) {
                    ticketErrorDiv.remove();
                }
            });
        });
    }

    function elementsCreate(ticketIntentionToken, Commerce) {
        elements = new Commerce.elements(ticketIntentionToken);
        let fortisDiv = document.getElementById('fortis_payment420');
        if (!fortisDiv) {
            fortisDiv = document.createElement('div');
            fortisDiv.id = 'fortis_payment420';
            fortisDiv.style.display = 'none';
            fortisDiv.style.margin = ticketIntentionData.appearance_options.marginSpacing;
            const container = document.getElementById('fortis-payment-form-container') || document.querySelector('div.main');
            if (container) {
                container.append(fortisDiv);
            }
        }

        elements.create({
            container: '#fortis_payment420',
            theme: ticketIntentionData.main_options.theme,
            environment: ticketIntentionData.main_options.environment,
            floatingLabels: ticketIntentionData.floatingLabels,
            showValidationAnimation: ticketIntentionData.showValidationAnimation,
            showReceipt: false,
            view: ticketIntentionData.main_options.view,
            showSubmitButton: false,
            appearance: {
                colorButtonSelectedBackground: ticketIntentionData.appearance_options.colorButtonSelectedBackground,
                colorButtonSelectedText: ticketIntentionData.appearance_options.colorButtonSelectedText,
                colorButtonActionBackground: ticketIntentionData.appearance_options.colorButtonActionBackground,
                colorButtonActionText: ticketIntentionData.appearance_options.colorButtonActionText,
                colorButtonBackground: ticketIntentionData.appearance_options.colorButtonBackground,
                colorButtonText: ticketIntentionData.appearance_options.colorButtonText,
                colorFieldBackground: ticketIntentionData.appearance_options.colorFieldBackground,
                colorFieldBorder: ticketIntentionData.appearance_options.colorFieldBorder,
                colorText: ticketIntentionData.appearance_options.colorText,
                colorLink: ticketIntentionData.appearance_options.colorLink,
                fontSize: ticketIntentionData.appearance_options.fontSize,
                marginSpacing: ticketIntentionData.appearance_options.marginSpacing,
                borderRadius: ticketIntentionData.appearance_options.borderRadius,
            }
        });

        elements.on('ready', async () => {
            if (spinner) {
                spinner.remove();
            }
            const isFortisSelected = isFortisPaymentSelected() || 
                                   window.checkoutConfig?.selectedPaymentMethod === 'fortis';
                                   
            if (isFortisSelected) {
                const container = document.getElementById('fortis-payment-form-container');
                const paymentForm = document.getElementById('fortis_payment420');
                if (paymentForm && container && paymentForm instanceof Node && paymentForm.parentNode !== container) {
                    container.appendChild(paymentForm);
                }
                if (paymentForm) {
                    paymentForm.style.display = 'block';
                }
                let $frame = jQuery('#fortis-framed-2567');
                $frame.show();
                $frame.parent().next().show();
                window.FortisTicketIntention.elements = elements;
            }
        });
    }

    async function cancelOrder(message = null) {

        const container = document.getElementById('fortis-payment-form-container');
        generateSpinner(container);

        window.checkoutConfig.selectedPaymentMethod = 'fortis';

        jQuery('#ticket-surcharge-disclaimer').html('');
        const fortisPaymentBlock = document.getElementById('fortis_payment420');
        if (fortisPaymentBlock) {
            fortisPaymentBlock.remove();
        }

        const response = await fetch(ticketIntentionData.ticketIntentionTokenUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            },
        });

        if (response.ok) {
            const responseData = await response.json();
            ticketIntentionData.ticketIntentionToken = responseData.ticketIntentionToken;
            generateTicketIntentionPayForm();
        }

        if (message) {
            const container = document.getElementById('fortis-payment-form-container');
            const errorDiv = document.createElement('div');
            errorDiv.id = 'ticketError';
            errorDiv.style.color = 'red';
            errorDiv.style.marginBottom = '10px';
            errorDiv.innerHTML = message;
            container.prepend(errorDiv);
        }
    }

    async function continueOrder(result, self, surchargeData, placeOrderAction) {
        if (!result || !result.data) {
            console.error('No result data available for continueOrder');
            return;
        }
        const cancelBtn = document.getElementById('cancel-order-btn');
        const continueBtn = document.getElementById('continue-order-btn');

        if (cancelBtn) {
            cancelBtn.disabled = true;
        }
        if (continueBtn) {
            continueBtn.disabled = true;
        }
        let ticketTransactionData = null;
        let transactionId = null;
        try {
            if (self && typeof self.afterPlaceOrder === 'function' && typeof self.getData === 'function' && self.messageContainer) {
                window.FortisTicketIntention.elements = null;

                try {
                    const payload = {
                        ticketIntention: result.data,
                        quoteId: window.checkoutConfig.quoteData.entity_id,
                        surchargeData: surchargeData,
                        fortisVault: window.fortisVault
                    };
                    const ticketTransactionResponse = await fetch(
                        window.checkoutConfig.payment.fortis.ticketTransactionUrl,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(payload)
                        }
                    );
                    if (!ticketTransactionResponse.ok) {
                        console.error('Ticket transaction request failed:', ticketTransactionResponse.status);
                        continueBtn.disabled = false;
                        cancelBtn.disabled = false;
                        return;
                    }
                    try {
                        ticketTransactionData = await ticketTransactionResponse.json();

                        transactionId = ticketTransactionData.id || (ticketTransactionData.data && ticketTransactionData.data.id);
                        const reasonCodeId = ticketTransactionData.reason_code_id || (ticketTransactionData.data && ticketTransactionData.data.reason_code_id);
                        if (!transactionId && !reasonCodeId) {
                            let error = ticketTransactionData.error || 'Payment failed. Please try again.';
                            console.error(error);
                            await cancelOrder(error);
                            return;
                        }
                        if (reasonCodeId !== 1000) {
                            await cancelOrder('Payment failed. Please try again.');
                            return;
                        }
                    } catch (jsonErr) {
                        const text = await ticketTransactionResponse.text();
                        console.error('Ticket transaction response is not valid JSON. Raw response:', text);
                        continueBtn.disabled = false;
                        cancelBtn.disabled = false;
                        return;
                    }
                } catch (err) {
                    console.error('Error running ticketTransaction:', err);
                    continueBtn.disabled = false;
                    cancelBtn.disabled = false;
                    return;
                }

                const placeOrder = placeOrderAction(self.getData(), false, self.messageContainer);
                jQuery.when(placeOrder).fail(function () {
                    self.isPlaceOrderActionAllowed(true);
                    console.error('Place order failed');
                }).done(async function () {
                    const ticketSurchargeDisclaimer = jQuery('#ticket-surcharge-disclaimer');
                    if (ticketSurchargeDisclaimer) {
                        ticketSurchargeDisclaimer.html('');
                    }
                    document.getElementById('fortis_payment420').style.display = 'none';
                    const container = document.getElementById('fortis-payment-form-container');
                    generateSpinner(container);

                    try {
                        const payload = {
                            transactionId: transactionId,
                            surchargeData: surchargeData,
                            '@action': 'ticket'
                        };
                        const response = await fetch(ticketIntentionData.returnUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(payload)
                        });

                        if (response.ok) {
                            const responseData = await response.json();
                            if (responseData.redirectTo) {
                                window.location.href = responseData.redirectTo;
                            } else {
                                console.error('Redirect URL not provided in response');
                            }
                        } else {
                            console.error('Request failed with status:', response.status);
                        }
                        spinner.remove();
                    } catch (error) {
                        console.error('Error in continueOrder:', error);
                    }
                });
            } else {
                console.error('self is missing required methods or properties (afterPlaceOrder, getData, messageContainer)');
            }
        } catch (error) {
            console.error('Error in continueOrder:', error);
        }
    }

    function generateSpinner(container) {
        spinner = document.createElement('div');
        spinner.id = 'fortis-spinner';
        spinner.style.cssText = 'display: flex; justify-content: center; align-items: center; height: 100%;';
        spinner.innerHTML = '<div style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite;"></div>';
        container.appendChild(spinner);

        const styleSheet = document.createElement('style');
        styleSheet.innerText = '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
        document.head.appendChild(styleSheet);
    }

    window.FortisTicketIntention = {
        generatePayForm: generateTicketIntentionPayForm,
        elements: null
    };

    window.FortisTicketIntention.generatePayForm = function () {
        generateTicketIntentionPayForm();
        window.FortisTicketIntention.elements = elements;
    };

    window.fortisGenerateTicketIntentionPayForm = generateTicketIntentionPayForm;

})();
