<?php

namespace Fortis\Fortis\Model;

use Magento\Framework\Option\ArrayInterface;

/**
 * @api
 * @since 100.0.2
 */
class FortisEnvironment implements ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'sandbox', 'label' => __('Sandbox')],
            ['value' => 'production', 'label' => __('Production')],
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
            'sandbox'         => __('Sandbox'),
            'production'    => __('Production'),
        ];
    }
}
