<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Data\OptionSourceInterface;
/**
 * @api
 * @since 100.0.2
 */
class FortisTheme implements OptionSourceInterface
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
}
