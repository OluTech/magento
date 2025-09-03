<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * @api
 * @since 100.0.2
 */
class IntentionFlow implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'transaction-intention', 'label' => __('Order Then Payment (Default)')],
            ['value' => 'ticket-intention', 'label' => __('Payment Then Order')],
        ];
    }
}
