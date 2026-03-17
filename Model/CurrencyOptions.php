<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Currency options for Fortis payment method
 */
class CurrencyOptions implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'USD', 'label' => __('USD - US Dollar')],
            ['value' => 'ARS', 'label' => __('ARS - Argentine Peso')],
            ['value' => 'AUD', 'label' => __('AUD - Australian Dollar')],
            ['value' => 'BRL', 'label' => __('BRL - Brazilian Real')],
            ['value' => 'CAD', 'label' => __('CAD - Canadian Dollar')],
            ['value' => 'CLP', 'label' => __('CLP - Chilean Peso')],
            ['value' => 'COP', 'label' => __('COP - Colombian Peso')],
            ['value' => 'EUR', 'label' => __('EUR - Euro')],
            ['value' => 'PYG', 'label' => __('PYG - Paraguayan Guaraní')],
            ['value' => 'INR', 'label' => __('INR - Indian Rupee')],
            ['value' => 'MXN', 'label' => __('MXN - Mexican Peso')],
            ['value' => 'ILS', 'label' => __('ILS - Israeli New Sheqel')],
            ['value' => 'NZD', 'label' => __('NZD - New Zealand Dollar')],
            ['value' => 'PEN', 'label' => __('PEN - Peruvian Sol (Nuevo Sol)')],
            ['value' => 'PHP', 'label' => __('PHP - Philippine Peso')],
            ['value' => 'GBP', 'label' => __('GBP - Pound Sterling')],
            ['value' => 'SGD', 'label' => __('SGD - Singapore Dollar')],
            ['value' => 'KRW', 'label' => __('KRW - South Korean Won')],
            ['value' => 'JPY', 'label' => __('JPY - Japanese Yen')],
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
            'USD' => __('USD - US Dollar'),
            'ARS' => __('ARS - Argentine Peso'),
            'AUD' => __('AUD - Australian Dollar'),
            'BRL' => __('BRL - Brazilian Real'),
            'CAD' => __('CAD - Canadian Dollar'),
            'CLP' => __('CLP - Chilean Peso'),
            'COP' => __('COP - Colombian Peso'),
            'EUR' => __('EUR - Euro'),
            'PYG' => __('PYG - Paraguayan Guaraní'),
            'INR' => __('INR - Indian Rupee'),
            'MXN' => __('MXN - Mexican Peso'),
            'ILS' => __('ILS - Israeli New Sheqel'),
            'NZD' => __('NZD - New Zealand Dollar'),
            'PEN' => __('PEN - Peruvian Sol (Nuevo Sol)'),
            'PHP' => __('PHP - Philippine Peso'),
            'GBP' => __('GBP - Pound Sterling'),
            'SGD' => __('SGD - Singapore Dollar'),
            'KRW' => __('KRW - South Korean Won'),
            'JPY' => __('JPY - Japanese Yen'),
        ];
    }
}
