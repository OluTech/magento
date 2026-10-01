<?php

namespace Fortispay\Fortis\Controller\Api;

use Fortispay\Fortis\Service\FortisMethodService;
use Fortispay\Fortis\Service\RateLimiter;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class TicketIntentionToken implements HttpGetActionInterface
{
    private const RATE_LIMIT_ACTION = 'ticketintentiontoken';

    private const RATE_LIMIT_MAX_ATTEMPTS = 10;

    private const RATE_LIMIT_WINDOW_SECONDS = 60;

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
     * @var RateLimiter
     */
    private RateLimiter $rateLimiter;

    /**
     * @param JsonFactory $resultJsonFactory
     * @param RequestInterface $request
     * @param LoggerInterface $logger
     * @param FortisMethodService $fortisMethodService
        * @param RateLimiter $rateLimiter
     */
    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        LoggerInterface $logger,
        FortisMethodService $fortisMethodService,
        RateLimiter $rateLimiter
    ) {
        $this->resultJsonFactory   = $resultJsonFactory;
        $this->logger              = $logger;
        $this->request             = $request;
        $this->fortisMethodService = $fortisMethodService;
        $this->rateLimiter         = $rateLimiter;
    }

    /**
     * Return intention token JSON payload.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->rateLimiter->isAllowed(
            self::RATE_LIMIT_ACTION,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS
        )) {
            $result->setHttpResponseCode(429);
            return $result->setData(['error' => __('Too many requests. Please wait a moment and try again.')]);
        }

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
