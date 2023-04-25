<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Option\ArrayInterface;

/**
 * @api
 * @since 100.0.2
 */
class FortisTheme implements ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'default', 'label' => __('Default')],
            ['value' => 'dark', 'label' => __('Dark')],
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
            'default' => __('Default'),
            'dark'    => __('Dark'),
        ];
    }
}
