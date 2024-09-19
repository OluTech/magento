require(['fortis-commerce', 'mage/url',], function(Commerce, _urlBuilder) {

    if (!document.getElementById('fortis_payment327')) {
        generateIFrame();
    }

    function generateIFrame() {
        const config = window.fortisData;

        const fortisDiv = document.createElement('div');
        fortisDiv.id = 'fortis_payment327';
        fortisDiv.style.marginBottom = config.appearance_options.marginSpacing;

        let cancelBtn;

        const checkoutPaymentBlock = document.querySelector('.payment-method._active .payment-method-content');
        if (checkoutPaymentBlock) {
            cancelBtn = buildCancelButton(config, true);
            checkoutPaymentBlock.append(fortisDiv);
            checkoutPaymentBlock.append(cancelBtn);
        } else {
            cancelBtn = buildCancelButton(config, false);
            document.querySelector('div.main').append(fortisDiv);
            document.querySelector('div.main').append(cancelBtn);
        }

        setTimeout(() => {
            const elements = new Commerce.elements(config.client_token);
            elements.create({
                container: '#fortis_payment327',
                theme: config.main_options.theme,
                environment: config.main_options.environment,
                floatingLabels: config.floatingLabels,
                showValidationAnimation: config.showValidationAnimation,
                showReceipt: false,
                digitalWallets: config.digitalWallets,
                view: config.main_options.view,
                appearance: {
                    colorButtonSelectedBackground: config.appearance_options.colorButtonSelectedBackground,
                    colorButtonSelectedText: config.appearance_options.colorButtonSelectedText,
                    colorButtonActionBackground: config.appearance_options.colorButtonActionBackground,
                    colorButtonActionText: config.appearance_options.colorButtonActionText,
                    colorButtonBackground: config.appearance_options.colorButtonBackground,
                    colorButtonText: config.appearance_options.colorButtonText,
                    colorFieldBackground: config.appearance_options.colorFieldBackground,
                    colorFieldBorder: config.appearance_options.colorFieldBorder,
                    colorText: config.appearance_options.colorText,
                    colorLink: config.appearance_options.colorLink,
                    fontSize: config.appearance_options.fontSize,
                    marginSpacing: config.appearance_options.marginSpacing,
                    borderRadius: config.appearance_options.borderRadius,
                },
                fields: {
                    additional: [
                        {name: 'description', required: true, value: config.orderId, hidden: true},
                        {name: 'transaction_api_id', hidden: true, value: config.guid},
                    ],
                    billing: config.billingFields
                }
            });

            elements.on('ready', function () {
                jQuery('#fortis-saved_cards').hide();
                cancelBtn.style.display = 'inline-block';
            });

            elements.on('paymentFinished', (result) => {
                if(result.status === 'approved') {
                    console.log('approved');
                } else {
                    console.log('failed');
                }
                console.log(result);
            });

            elements.on('done', async (result) => {
                cancelBtn.style.display = 'none';
                console.log(result);
                const response = await fetch(config.redirectUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(result.data)
                });
                if (response.status === 200) {
                    const redirect = await response.json();
                    setTimeout(() => {
                        window.location.href = redirect.redirectTo;
                    }, 2000);
                }
            });

            elements.on('error', (error) => {
                console.log(error);
            });
        }, 1000);
    }

    function buildCancelButton(config, isCheckout)
    {
        const cancelBtn = document.createElement('a');
        let url = 'redirect/continueshopping?order_id=' + config.orderId;
        if (isCheckout) {
            url = 'fortis/' + url;
        }
        cancelBtn.href = _urlBuilder.build(url);
        cancelBtn.textContent = config.appearance_options.cancelButtonText;
        cancelBtn.style.padding = '10px 20px';
        cancelBtn.style.backgroundColor = config.appearance_options.colorButtonBackground;
        cancelBtn.style.color = config.appearance_options.colorButtonText;
        cancelBtn.style.textDecoration = 'none';
        cancelBtn.style.borderRadius = config.appearance_options.borderRadius;
        cancelBtn.style.fontSize = config.appearance_options.fontSize;
        cancelBtn.style.display = 'none';
        cancelBtn.style.marginTop = config.appearance_options.marginSpacing;

        cancelBtn.onmouseover = function() {
            cancelBtn.style.backgroundColor = config.appearance_options.colorButtonActionBackground;
            cancelBtn.style.color = config.appearance_options.colorButtonActionText;
        };
        cancelBtn.onmouseout = function() {
            cancelBtn.style.backgroundColor = config.appearance_options.colorButtonBackground;
            cancelBtn.style.color = config.appearance_options.colorButtonText;
        };

        return cancelBtn;
    }
});
