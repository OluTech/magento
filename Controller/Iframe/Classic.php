<?php

namespace Fortispay\Fortis\Controller\Iframe;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Fortispay\Fortis\Service\CheckoutProcessor;
use Psr\Log\LoggerInterface;

class Classic implements HttpPostActionInterface, HttpGetActionInterface
{
    private PageFactory $pageFactory;
    private ResultFactory $resultFactory;
    private CheckoutProcessor $checkoutProcessor;
    private LoggerInterface $logger;

    /**
     * @param PageFactory $pageFactory
     * @param ResultFactory $resultFactory
     * @param CheckoutProcessor $checkoutProcessor
     */
    public function __construct(
        PageFactory $pageFactory,
        ResultFactory $resultFactory,
        CheckoutProcessor $checkoutProcessor,
        LoggerInterface $logger,
    ) {
        $this->pageFactory       = $pageFactory;
        $this->resultFactory     = $resultFactory;
        $this->checkoutProcessor = $checkoutProcessor;
        $this->logger            = $logger;
    }

    /**
     * @throws LocalizedException
     */
    public function execute()
    {
        $pageObject = $this->pageFactory->create();

        $blockContent = $pageObject->getLayout()
            ->getBlock('fortis_redirect')
            ->toHtml();

        try {
            $this->checkoutProcessor->initOrderState();
        } catch (LocalizedException $e) {
            $this->logger->error('Could not initialize order: ' . $e->getMessage());
        }

        $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $resultRaw->setContents($blockContent);

        return $resultRaw;
    }
}
