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
    url,
    CCForm,
    VaultEnabler
  ) {
    'use strict'

    return Component.extend({
      defaults: {
        template: 'Fortis_Fortis/payment/fortis'
      },
      getData: function () {
        let fortisPaymentType = $('input[name=fortis-payment-type]:checked').val();
        let fortisVault;
        if ($('#fortis-vault-method').prop('checked') === true) {
          fortisVault = 1;
        } else {
          const fortisSavedCard = $('#fortis-saved_cards').find(':selected').val();
          if (fortisSavedCard != 'undefined') {
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
            'fortis-payment-type': fortisPaymentType
          }
        }
      },

      /**
       * @returns {Boolean}
       */
      isFortisVaultEnabled: function () {
        return window.checkoutConfig.payment.fortis.isVault
      },

      /**
       * @returns {json}
       */
      getPaymentTypesList: function () {
        return window.checkoutConfig.payment.fortis.paymentTypeList
      },

      /**
       * @returns {json}
       */
      getFortisSavedCardList: function () {
        return window.checkoutConfig.payment.fortis.saved_card_data
      },

      /**
       * @returns {json}
       */
      checkFortisSavedCard: function () {
        return window.checkoutConfig.payment.fortis.card_count
      },

      /**
       * @returns {Boolean}
       */
      isPaymentTypes: function () {
        const paymentTypes = window.checkoutConfig.payment.fortis.paymentTypes
        if ('null' != paymentTypes) {
          return true
        }
        return false
      },

      placeOrder: function (data, event) {
        if (event) {
          event.preventDefault()
        }
        var self = this,
          placeOrder,
          emailValidationResult = customer.isLoggedIn(),
          loginFormSelector = 'form[data-role=email-with-possible-login]'
        if (!customer.isLoggedIn()) {
          $(loginFormSelector).validation()
          emailValidationResult = Boolean($(loginFormSelector + ' input[name=username]').valid())
        }
        if (emailValidationResult && this.validate() && additionalValidators.validate()) {
          this.isPlaceOrderActionAllowed(false)
          placeOrder = placeOrderAction(this.getData(), false, this.messageContainer)
          $.when(placeOrder).fail(function () {
            self.isPlaceOrderActionAllowed(true)
          }).done(this.afterPlaceOrder.bind(this))
          return true
        }
      },
      getCode: function () {
        return 'fortis'
      },
      selectPaymentMethod: function () {
        selectPaymentMethodAction(this.getData())
        checkoutData.setSelectedPaymentMethod(this.item.method)
        return true
      },
      /**
       * Get value of instruction field.
       * @returns {String}
       */
      getInstructions: function () {
        return window.checkoutConfig.payment.instructions[this.item.method]
      },
      isAvailable: function () {
        return quote.totals().grand_total <= 0
      },
      afterPlaceOrder: function () {
        window.location.replace(url.build(window.checkoutConfig.payment.fortis.redirectUrl))
      },
      /** Returns payment acceptance mark link path */
      getPaymentAcceptanceMarkHref: function () {
        return window.checkoutConfig.payment.fortis.paymentAcceptanceMarkHref
      },
      /** Returns payment acceptance mark image path */
      getPaymentAcceptanceMarkSrc: function () {
        return window.checkoutConfig.payment.fortis.paymentAcceptanceMarkSrc
      }

    })
  }
)
