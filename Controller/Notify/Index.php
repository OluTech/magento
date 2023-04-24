<?php

namespace Fortispay\Fortis\Controller\Notify;

/**
 * Check for existence of CsrfAwareActionInterface - only v2.3.0+
 */
if (interface_exists("Magento\Framework\App\CsrfAwareActionInterface")) {
    class_alias('Fortispay\Fortis\Controller\Notify\Indexm230', 'Fortispay\Fortis\Controller\Notify\Index');
} else {
    class_alias('Fortispay\Fortis\Controller\Notify\Indexm220', 'Fortispay\Fortis\Controller\Notify\Index');
}
