<?php

namespace Fortispay\Fortis\Controller\Api;

use Fortispay\Fortis\Service\FortisMethodService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class TicketIntentionToken implements HttpGetActionInterface
{
    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var FortisMethodService
     */
    private FortisMethodService $fortisMethodService;

    /**
     * @var JsonFactory
     */
    private JsonFactory $resultJsonFactory;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param JsonFactory $resultJsonFactory
     * @param RequestInterface $request
     * @param LoggerInterface $logger
     * @param FortisMethodService $fortisMethodService
     */
    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        LoggerInterface $logger,
        FortisMethodService $fortisMethodService,
    ) {
        $this->resultJsonFactory   = $resultJsonFactory;
        $this->logger              = $logger;
        $this->request             = $request;
        $this->fortisMethodService = $fortisMethodService;
    }

    /**
     * Return intention token JSON payload.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        try {
            $ticketIntentionToken = $this->fortisMethodService->getTicketIntentionToken();
            $result->setData(['ticketIntentionToken' => $ticketIntentionToken]);
        } catch (LocalizedException $e) {
            $this->logger->error($e);
            $result->setHttpResponseCode(500);
            $result->setData(['error' => $e->getMessage()]);
        }

        return $result;
    }
}
