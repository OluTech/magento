<?php

namespace Fortis\Fortis\Controller\Notify;

/**
 * Check for existence of CsrfAwareActionInterface - only v2.3.0+
 */
if (interface_exists("Magento\Framework\App\CsrfAwareActionInterface")) {
    class_alias('Fortis\Fortis\Controller\Notify\Indexm230', 'Fortis\Fortis\Controller\Notify\Index');
} else {
    class_alias('Fortis\Fortis\Controller\Notify\Indexm220', 'Fortis\Fortis\Controller\Notify\Index');
}
