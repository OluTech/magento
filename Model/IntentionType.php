<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Option\ArrayInterface;

/**
 * @api
 * @since 100.0.2
 */
class IntentionType implements ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'sale', 'label' => __('Sale')],
            ['value' => 'auth-only', 'label' => __('Authorisation Only')],
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
            'sale'         => __('Sale'),
            'auth-only'    => __('Authorisation Only'),
        ];
    }
}
