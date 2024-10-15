<?php

namespace Fortispay\Fortis\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Checkout\Model\Cart;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

class QuoteRegenerator
{
    protected OrderRepositoryInterface $orderRepository;
    protected QuoteFactory $quoteFactory;
    protected Cart $cart;
    protected CustomerSession $customerSession;
    protected OrderManagementInterface $orderManagement;
    protected LoggerInterface $logger;
    protected CheckoutSession $checkoutSession;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        QuoteFactory $quoteFactory,
        Cart $cart,
        CustomerSession $customerSession,
        OrderManagementInterface $orderManagement,
        LoggerInterface $logger,
        CheckoutSession $checkoutSession
    ) {
        $this->orderRepository = $orderRepository;
        $this->quoteFactory    = $quoteFactory;
        $this->cart            = $cart;
        $this->customerSession = $customerSession;
        $this->orderManagement = $orderManagement;
        $this->logger          = $logger;
        $this->checkoutSession = $checkoutSession;
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
        $quote->save();

        $this->cart->setQuote($quote);
        $this->cart->save();

        if ($order->canCancel()) {
            $order->cancel();
            $this->orderRepository->save($order);
        }

        return $quote;
    }
}
