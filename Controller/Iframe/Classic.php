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
    /**
     * @var PageFactory
     */
    private PageFactory $pageFactory;

    /**
     * @var ResultFactory
     */
    private ResultFactory $resultFactory;

    /**
     * @var CheckoutProcessor
     */
    private CheckoutProcessor $checkoutProcessor;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param PageFactory $pageFactory
     * @param ResultFactory $resultFactory
     * @param CheckoutProcessor $checkoutProcessor
     * @param LoggerInterface $logger
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
     * Execute checkout iframe request.
     *
     * @return \Magento\Framework\Controller\Result\Raw
     * @throws LocalizedException
     */
    public function execute()
    {
        try {
            $this->checkoutProcessor->initOrderState();
        } catch (LocalizedException $e) {
            $this->logger->error('Could not initialize order: ' . $e->getMessage());
        }

        $pageObject = $this->pageFactory->create();

        $blockContent = $pageObject->getLayout()
            ->getBlock('fortis_redirect')
            ->toHtml();

        $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $resultRaw->setContents($blockContent);

        return $resultRaw;
    }
}
