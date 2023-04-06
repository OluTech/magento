<?php

namespace Fortis\Fortis\Controller;

if (interface_exists("Magento\Framework\App\CsrfAwareActionInterface")) {
    class_alias('Fortis\Fortis\Controller\AbstractFortism230', 'Fortis\Fortis\Controller\AbstractFortis');
} else {
    class_alias('Fortis\Fortis\Controller\AbstractFortism220', 'Fortis\Fortis\Controller\AbstractFortis');
}
