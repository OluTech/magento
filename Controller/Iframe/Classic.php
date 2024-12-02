<?php

namespace Fortispay\Fortis\Controller\Iframe;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\PageFactory;
use Fortispay\Fortis\Service\CheckoutProcessor;

class Classic implements HttpPostActionInterface, HttpGetActionInterface
{
    private PageFactory $pageFactory;
    private ResultFactory $resultFactory;
    private CheckoutProcessor $checkoutProcessor;

    /**
     * @param PageFactory $pageFactory
     * @param ResultFactory $resultFactory
     * @param CheckoutProcessor $checkoutProcessor
     */
    public function __construct(
        PageFactory $pageFactory,
        ResultFactory $resultFactory,
        CheckoutProcessor $checkoutProcessor
    ) {
        $this->pageFactory       = $pageFactory;
        $this->resultFactory     = $resultFactory;
        $this->checkoutProcessor = $checkoutProcessor;
    }

    public function execute()
    {
        $pageObject = $this->pageFactory->create();

        $blockContent = $pageObject->getLayout()
                                   ->getBlock('fortis_redirect')
                                   ->toHtml();

        $this->checkoutProcessor->initOrderState();
        $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $resultRaw->setContents($blockContent);

        return $resultRaw;
    }
}
