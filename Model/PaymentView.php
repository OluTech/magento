<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Option\ArrayInterface;

/**
 * @api
 * @since 100.0.2
 */
class PaymentView implements ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'single', 'label' => __('Credit Card Only')],
            ['value' => 'classic', 'label' => __('Credit Card & ACH')],
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
            'single'  => __('Credit Card Only'),
            'classic' => __('Credit Card & ACH'),
        ];
    }
}
