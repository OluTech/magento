<?php

namespace Fortispay\Fortis\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;
use Magento\Quote\Model\QuoteRepository;

class QuoteRegenerator
{
    private OrderRepositoryInterface $orderRepository;
    private QuoteFactory $quoteFactory;
    private LoggerInterface $logger;
    private CheckoutSession $checkoutSession;
    private QuoteRepository $quoteRepository;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param QuoteFactory $quoteFactory
     * @param LoggerInterface $logger
     * @param CheckoutSession $checkoutSession
     * @param QuoteRepository $quoteRepository
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        QuoteFactory $quoteFactory,
        LoggerInterface $logger,
        CheckoutSession $checkoutSession,
        QuoteRepository $quoteRepository,
    ) {
        $this->orderRepository = $orderRepository;
        $this->quoteFactory    = $quoteFactory;
        $this->logger = $logger;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * @param int|null $orderId
     *
     * @return Quote
     * @throws LocalizedException
     */
    public function regenerateQuote(?int $orderId): Quote
    {
        $quote = $this->quoteFactory->create();

        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order) {
            try {
                $order = $this->orderRepository->get($orderId);
            } catch (NoSuchEntityException $e) {
                $this->logger->error("Order with ID {$orderId} does not exist.");

                return $quote;
            }
        }

        $quote->setStoreId($order->getStoreId());

        if ($order->getCustomerId()) {
            $quote->setCustomerId($order->getCustomerId())
                  ->setCustomerEmail($order->getCustomerEmail())
                  ->setCustomerIsGuest(false);
        } else {
            $quote->setCustomerIsGuest(true);
        }

        foreach ($order->getAllVisibleItems() as $item) {
            $quote->addProduct($item->getProduct(), $item->getQtyOrdered());
        }

        if (!$order->getIsVirtual()) {
            $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();
        }

        $quote->collectTotals();
        $this->quoteRepository->save($quote);

        if ($order->canCancel()) {
            $order->cancel();
            $this->orderRepository->save($order);
        }

        return $quote;
    }
}
