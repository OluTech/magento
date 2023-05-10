<?php

namespace Fortispay\Fortis\Controller\Redirect;

use Fortispay\Fortis\Controller\AbstractFortis;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
abstract class Cron extends AbstractFortis
{
    public function getResponse()
    {
        return $this->getResponse();
    }
}
