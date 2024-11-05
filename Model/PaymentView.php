<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * @api
 * @since 100.0.2
 */
class PaymentView implements OptionSourceInterface
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
}
