<?php

namespace Fortis\Fortis\Controller\Redirect;

use Magento\Framework\Controller\ResultFactory;
use Fortis\Fortis\Controller\AbstractFortis;

class Order extends AbstractFortis
{

    public function execute()
    {
        $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $formFields = $this->_paymentMethod->getFortisOrderToken();
        $response   = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $response->setHeader('Content-type', 'text/plain');
        $response->setHeader('X-Magento-Cache-Control', ' max-age=0, must-revalidate, no-cache, no-store Age: 0');
        $response->setHeader('X-Magento-Cache-Debug', 'MISS');
        $response->setContents(
            json_encode($formFields)
        );

        return $response;
    }
}
