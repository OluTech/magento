<?php

namespace Fortispay\Fortis\Controller\Redirect;

use Fortispay\Fortis\Controller\AbstractFortis;
use Magento\Framework\Controller\ResultFactory;

class Order extends AbstractFortis
{

    /**
     * Execute
     *
     * @return mixed
     */
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

    public function getResponse()
    {
        return $this->getResponse();
    }
}
