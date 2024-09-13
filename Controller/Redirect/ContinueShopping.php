<?php

namespace Fortispay\Fortis\Controller\Redirect;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Fortispay\Fortis\Service\QuoteRegenerator;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;

class ContinueShopping implements HttpGetActionInterface
{
    protected QuoteRegenerator $quoteRegenerator;
    protected ResultFactory $resultFactory;
    protected RequestInterface $request;

    public function __construct(
        QuoteRegenerator $quoteRegenerator,
        ResultFactory $resultFactory,
        RequestInterface $request
    ) {
        $this->quoteRegenerator = $quoteRegenerator;
        $this->resultFactory    = $resultFactory;
        $this->request          = $request;
    }

    /**
     * @throws LocalizedException
     */
    public function execute()
    {
        $orderId = $this->request->getParam('order_id');

        $this->quoteRegenerator->regenerateQuote($orderId);

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('checkout/cart');
    }
}
