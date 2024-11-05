<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * @api
 * @since 100.0.2
 */
class IntentionType implements OptionSourceInterface
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
}
