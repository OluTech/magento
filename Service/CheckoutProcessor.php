<?php

namespace Fortispay\Fortis\Service;

use Exception;
use Fortispay\Fortis\Model\Fortis;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Psr\Log\LoggerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\QuoteRepository;
use Magento\Customer\Model\Url;

class CheckoutProcessor
{
    private const CART_URL = 'checkout/cart';

    private LoggerInterface $logger;
    private Order $order;
    private OrderRepositoryInterface $orderRepository;
    private CheckoutSession $checkoutSession;
    private QuoteRepository $quoteRepository;
    private ResultFactory $resultFactory;
    private Url $customerUrl;

    /**
     * @param LoggerInterface $logger
     * @param Order $order
     * @param OrderRepositoryInterface $orderRepository
     * @param CheckoutSession $checkoutSession
     * @param QuoteRepository $quoteRepository
     * @param ResultFactory $resultFactory
     * @param Url $customerUrl
     * @param Fortis $paymentMethod
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        LoggerInterface $logger,
        Order $order,
        OrderRepositoryInterface $orderRepository,
        CheckoutSession $checkoutSession,
        QuoteRepository $quoteRepository,
        ResultFactory $resultFactory,
        Url $customerUrl
    ) {
        $this->logger          = $logger;
        $this->order           = $order;
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->resultFactory   = $resultFactory;
        $this->customerUrl     = $customerUrl;
    }

    /**
     * Instantiate
     *
     * @return void
     * @throws LocalizedException
     */
    public function initCheckout(): void
    {
        $pre = __METHOD__ . " : ";
        $this->logger->debug($pre . 'bof');
        $this->initOrderState();

        if ($this->order->getQuoteId()) {
            $this->checkoutSession->setFortisQuoteId($this->checkoutSession->getQuoteId());
            $this->checkoutSession->setFortisSuccessQuoteId($this->checkoutSession->getLastSuccessQuoteId());
            $this->checkoutSession->setFortisRealOrderId($this->checkoutSession->getLastRealOrderId());

            $quote = $this->checkoutSession->getQuote();
            $quote->setIsActive(false);
            $this->quoteRepository->save($quote);
        }

        $this->logger->debug($pre . 'eof');
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    public function initOrderState(): void
    {
        $this->order = $this->checkoutSession->getLastRealOrder();

        if (!$this->order->getId()) {
            throw new LocalizedException(__('We could not find "Order" for processing'));
        }

        if ($this->order->getState() != Order::STATE_PENDING_PAYMENT) {
            $this->order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
            $this->orderRepository->save($this->order);
        }
    }

    public function getRedirectToCartObject(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setUrl(self::CART_URL);

        return $redirect;
    }


    /**
     * Returns login url parameter for redirect
     *
     * @return string
     */
    public function getLoginUrl(): string
    {
        return $this->customerUrl->getLoginUrl();
    }

    /**
     * @return string|null
     */
    public function getCurrentBillingPostalCode(): ?string
    {
        try {
            $quote = $this->checkoutSession->getQuote();

            $billingAddress = $quote->getBillingAddress();

            $postalCode = $billingAddress->getPostcode();

            return $postalCode ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    public function getAddresses(): array
    {
        $quote = $this->checkoutSession->getQuote();

        $addressAll = $quote->getBillingAddress();
        $address    = implode(', ', $addressAll->getStreet());
        $country    = $addressAll->getCountryId() ?? '';
        $city       = $addressAll->getCity() ?? '';
        $postalCode = $addressAll->getPostcode() ?? '';
        $regionCode = $addressAll->getRegionCode() ?? '';

        return [$address, $country, $city, $postalCode, $regionCode];
    }

    /**
     * Get the current tax amount and subtotal from the checkout quote
     * @return array|null
     */
    public function getCheckoutTotals(): ?array
    {
        try {
            $quote = $this->checkoutSession->getQuote();

            if (!$quote->getId() || !$quote->getIsActive()) {
                return [
                    'subtotal'    => 0,
                    'tax_amount'  => 0,
                    'grand_total' => 0
                ];
            }

            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();

            $shippingAddress = $quote->getShippingAddress();
            $billingAddress  = $quote->getBillingAddress();
            $subtotal        = $quote->getSubtotalWithDiscount() + $shippingAddress->getShippingAmount();

            $taxAmount = $shippingAddress->getTaxAmount();
            if ($taxAmount === null || $taxAmount == 0) {
                $taxAmount = $billingAddress->getTaxAmount();
            }

            $grandTotal = $quote->getGrandTotal();

            return [
                'subtotal'    => $subtotal ?: 0,
                'tax_amount'  => $taxAmount ?: 0,
                'grand_total' => $grandTotal ?: 0
            ];
        } catch (Exception $e) {
            $this->logger->error('Error calculating quote totals: ' . $e->getMessage());

            return null;
        }
    }
}
