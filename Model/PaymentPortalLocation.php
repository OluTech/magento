<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Option\ArrayInterface;

/**
 * @api
 * @since 100.0.2
 */
class PaymentPortalLocation implements ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'iframe', 'label' => __('On Checkout')],
            ['value' => 'redirect', 'label' => __('Redirect')],
        ];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'iframe'   => __('On Checkout'),
            'redirect' => __('Redirect'),
        ];
    }
}
