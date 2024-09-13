<?php

namespace Fortispay\Fortis\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Checkout\Model\Cart;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Sales\Api\OrderManagementInterface;

class QuoteRegenerator
{
    protected OrderRepositoryInterface $orderRepository;
    protected QuoteFactory $quoteFactory;
    protected Cart $cart;
    protected CustomerSession $customerSession;
    protected OrderManagementInterface $orderManagement;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        QuoteFactory $quoteFactory,
        Cart $cart,
        CustomerSession $customerSession,
        OrderManagementInterface $orderManagement
    ) {
        $this->orderRepository = $orderRepository;
        $this->quoteFactory    = $quoteFactory;
        $this->cart            = $cart;
        $this->customerSession = $customerSession;
        $this->orderManagement = $orderManagement;
    }

    /**
     * @param $orderId
     *
     * @return Quote
     * @throws LocalizedException
     */
    public function regenerateQuote($orderId): Quote
    {
        $order = $this->orderRepository->get($orderId);

        $quote = $this->quoteFactory->create();
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

        $this->orderManagement->cancel($orderId);

        return $quote;
    }
}
