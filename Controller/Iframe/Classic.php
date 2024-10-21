<?php

namespace Fortispay\Fortis\Controller\Iframe;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\PageFactory;

class Classic implements HttpPostActionInterface, HttpGetActionInterface
{
    private PageFactory $pageFactory;
    private ResultFactory $resultFactory;

    /**
     * @param PageFactory $pageFactory
     * @param ResultFactory $resultFactory
     */
    public function __construct(
        PageFactory $pageFactory,
        ResultFactory $resultFactory,
    ) {
        $this->pageFactory   = $pageFactory;
        $this->resultFactory = $resultFactory;
    }

    public function execute()
    {
        $pageObject = $this->pageFactory->create();

        $blockContent = $pageObject->getLayout()
                                   ->getBlock('fortis_redirect')
                                   ->toHtml();

        $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $resultRaw->setContents($blockContent);

        return $resultRaw;
    }
}
