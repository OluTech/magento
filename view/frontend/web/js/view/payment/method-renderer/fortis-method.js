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
    'Magento_Vault/js/view/payment/vault-enabler'
  ],
  function ($,
    Component,
    placeOrderAction,
    selectPaymentMethodAction,
    customer,
    checkoutData,
    additionalValidators,
    _urlBuilder,
  ) {
    'use strict';
    let fortisPaymentType;
    let fortisSavedCard = undefined;
    let surchargeData;
    let spinner = null;

    return Component.extend({
      defaults: {
        template: 'Fortispay_Fortis/payment/fortis'
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

        return {
          'method': this.item.method,
          'additional_data': {
            'fortis-vault-method': fortisVault,
            'fortis-payment-type': fortisPaymentType,
            'fortis-surcharge-data': JSON.stringify(surchargeData),
          }
        };
      },

      /**
       * @returns {Boolean}
       */
      isFortisVaultEnabled: function () {
        return window.checkoutConfig.payment.fortis.isVault;
      },

      /**
       * @return {Boolean}
       */
      useFortisVaultEnabled: function () {
        return true;
      },

      /**
       * True if payment form rendered on checkout page
       *
       * @returns {boolean}
       */
      isFramed: function () {
        return typeof window.checkoutConfig.payment[this.getCode()] !== 'undefined' &&
          window.checkoutConfig.payment[this.getCode()].isCheckoutIframe;
      },

      /**
       *
       * @returns {boolean}
       */
      frameSingleView: function () {
        return typeof window.checkoutConfig.payment[this.getCode()] !== 'undefined' &&
          window.checkoutConfig.payment[this.getCode()].isSingleView;
      },

      /**
       * @returns {json}
       */
      getPaymentTypesList: function () {
        return window.checkoutConfig.payment.fortis.paymentTypeList;
      },

      /**
       * @returns {json}
       */
      getFortisSavedCardList: function () {
        return window.checkoutConfig.payment.fortis.saved_card_data;
      },

      /**
       * @returns {json}
       */
      checkFortisSavedCard: function () {
        return window.checkoutConfig.payment.fortis.card_count;
      },

      /**
       * @returns {Boolean}
       */
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
        var self = this,
          placeOrder,
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
          }).done(this.afterPlaceOrder.bind(this));
          return true;
        }
      },
      getCode: function () {
        return 'fortis';
      },
      selectPaymentMethod: function () {
        selectPaymentMethodAction(this.getData());
        checkoutData.setSelectedPaymentMethod(this.item.method);
        return true;
      },
      /**
       * Get value of instruction field.
       * @returns {String}
       */
      getInstructions: function () {
        return window.checkoutConfig.payment.instructions[this.item.method];
      },
      isAvailable: function () {
        return quote.totals().grand_total <= 0;
      },
      afterPlaceOrder: function () {
        let $frame = $('#fortis-framed-2567');
        $frame.hide();
        $frame.parent().next().hide();
        if (!this.isFramed()) {
          window.location.replace(_urlBuilder.build(window.checkoutConfig.payment.fortis.redirectUrl));
        } else if (fortisSavedCard !== undefined && !(fortisSavedCard === 'new' || fortisSavedCard === 'new-save')) {
          window.location.replace(_urlBuilder.build(window.checkoutConfig.payment.fortis.redirectUrl));
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

          }).fail(function (response) {
            $frame.trigger('processStop');
          });
        }
      },
      /** Returns payment acceptance mark link path */
      getPaymentAcceptanceMarkHref: function () {
        return window.checkoutConfig.payment.fortis.paymentAcceptanceMarkHref;
      },
      /** Returns payment acceptance mark image path */
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

            var selectedOption = selectElement.options[selectElement.selectedIndex];
            var cardType = selectedOption.getAttribute('data-card-type');

            surchargeDisclaimer.html('');
            placeOrderBtn.prop('disabled', true);

            if (selectedValue !== 'new' && selectedValue !== 'new-save' && cardType !== 'ach') {
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
                fortisSavedCard = undefined;
                placeOrderBtn.prop('disabled', false);
            }
        },
      /** Return text for place order button **/
      getPlaceOrderBtn: function () {
          return window.checkoutConfig.payment.fortis.placeOrderBtnText
      },
        calculateSurcharge: function (publicHash) {
            // Returns a Promise
            return $.ajax({
                url: '/fortis/api/calculatesurcharge',
                method: 'GET',
                data: { public_hash: publicHash }
            });
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
