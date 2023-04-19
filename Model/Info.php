<?php

// @codingStandardsIgnoreFile

namespace Fortispay\Fortis\Model;

/**
 * Fortis payment information model
 *
 * Aware of all Fortis payment methods
 * Collects and provides access to Fortis-specific payment data
 * Provides business logic information about payment flow
 */
class Info
{
    /**
     * Apply a filter after getting value
     *
     * @param string $value
     *
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _getValue($value)
    {
        $label       = '';
        $outputValue = implode(', ', (array)$value);

        return sprintf('#%s%s', $outputValue, $outputValue == $label ? '' : ': ' . $label);
    }

}
