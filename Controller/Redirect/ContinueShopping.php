<?php

namespace Fortispay\Fortis\Controller\Redirect;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Fortispay\Fortis\Service\QuoteRegenerator;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;

class ContinueShopping implements HttpGetActionInterface
{
    /**
     * @var QuoteRegenerator
     */
    private QuoteRegenerator $quoteRegenerator;

    /**
     * @var ResultFactory
     */
    private ResultFactory $resultFactory;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @param QuoteRegenerator $quoteRegenerator
     * @param ResultFactory $resultFactory
     * @param RequestInterface $request
     */
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
     * Regenerate quote and redirect customer to cart.
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     * @throws LocalizedException
     */
    public function execute()
    {
        $orderId = $this->request->getParam('order_id');

        $this->quoteRegenerator->regenerateQuote($orderId);

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('checkout/cart');
    }
}
