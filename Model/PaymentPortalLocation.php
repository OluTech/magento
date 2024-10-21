<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * @api
 * @since 100.0.2
 */
class PaymentPortalLocation implements OptionSourceInterface
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
}
