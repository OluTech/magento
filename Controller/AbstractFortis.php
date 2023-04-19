<?php

namespace Fortispay\Fortis\Controller;

if (interface_exists("Magento\Framework\App\CsrfAwareActionInterface")) {
    class_alias('Fortispay\Fortis\Controller\AbstractFortism230', 'Fortispay\Fortis\Controller\AbstractFortis');
} else {
    class_alias('Fortispay\Fortis\Controller\AbstractFortism220', 'Fortispay\Fortis\Controller\AbstractFortis');
}
