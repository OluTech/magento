/*browser:true*/
/*global define*/
define(
  [
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
  ],
  function (Component,
    rendererList
  ) {
    'use strict'

    rendererList.push(
      {
        type: 'fortis',
        component: 'Fortis_Fortis/js/view/payment/method-renderer/fortis-method'
      }
    )
    /** Add view logic here if needed */
    return Component.extend({})
  }
)
