<?php

namespace Fortispay\Fortis\Block;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

class ColorPicker extends Field
{

    /**
     * Get Elements HTML
     *
     * @param AbstractElement $element
     *
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return '<input type="color" id="' .
               $element->getHtmlId() .
               '" name="' .
               $element->getName() .
               '" value="' .
               $element->getEscapedValue() .
               '"/>';
    }
}
