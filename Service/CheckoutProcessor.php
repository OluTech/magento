<?php

namespace Fortispay\Fortis\Service;

use Exception;
use Fortispay\Fortis\Model\Fortis;
use Magento\Directory\Model\CountryFactory;
use Magento\Customer\Api\AddressRepositoryInterface;
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
use Magento\Framework\UrlInterface;

class CheckoutProcessor
{
    private const CART_URL = 'checkout/cart';

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var Order
     */
    private Order $order;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var QuoteRepository
     */
    private QuoteRepository $quoteRepository;

    /**
     * @var ResultFactory
     */
    private ResultFactory $resultFactory;

    /**
     * @var Url
     */
    private Url $customerUrl;

    /**
     * @var AddressRepositoryInterface
     */
    private AddressRepositoryInterface $addressRepository;

    /**
     * @var CountryFactory
     */
    private CountryFactory $countryFactory;

    /**
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;

    /**
     * @param LoggerInterface $logger
     * @param Order $order
     * @param OrderRepositoryInterface $orderRepository
     * @param CheckoutSession $checkoutSession
     * @param QuoteRepository $quoteRepository
     * @param ResultFactory $resultFactory
     * @param Url $customerUrl
     * @param AddressRepositoryInterface $addressRepository
     * @param CountryFactory $countryFactory
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        LoggerInterface $logger,
        Order $order,
        OrderRepositoryInterface $orderRepository,
        CheckoutSession $checkoutSession,
        QuoteRepository $quoteRepository,
        ResultFactory $resultFactory,
        Url $customerUrl,
        AddressRepositoryInterface $addressRepository,
        CountryFactory $countryFactory,
        UrlInterface $urlBuilder
    ) {
        $this->logger            = $logger;
        $this->order             = $order;
        $this->orderRepository   = $orderRepository;
        $this->checkoutSession   = $checkoutSession;
        $this->quoteRepository   = $quoteRepository;
        $this->resultFactory     = $resultFactory;
        $this->customerUrl       = $customerUrl;
        $this->addressRepository = $addressRepository;
        $this->countryFactory    = $countryFactory;
        $this->urlBuilder        = $urlBuilder;
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
     * Initialize order state for Fortis checkout.
     *
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

    /**
     * Build redirect response object to cart page.
     *
     * @return ResultInterface
     */
    public function getRedirectToCartObject(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $cartUrl  = $this->urlBuilder->getUrl(self::CART_URL);
        $redirect->setUrl($cartUrl);

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
     * Get current billing postal code from quote.
     *
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

    /**
     * Get key billing address values from quote.
     *
     * @return array
     */
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
     *
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
            if ($taxAmount == 0) {
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

    /**
     * Get the currency code for the current checkout quote
     *
     * @return string|null
     */
    public function getCheckoutCurrency(): ?string
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            if ($quote && $quote->getId()) {
                return $quote->getQuoteCurrencyCode();
            }
        } catch (Exception $e) {
            $this->logger->error('Error getting quote currency: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Get formatted billing address from the checkout session quote.
     * Uses the same 3-level fallback as TicketTransaction:
     *   1. Quote billing address (if complete)
     *   2. Quote shipping address (if billing is incomplete)
     *   3. Customer's saved default billing address
     *
     * Returns null (non-blocking) if no address data is available at all.
     *
     * @param Order|null $order Optional order to use instead of quote
     * @return array|null
     */
    public function getBillingAddressData(?Order $order = null): ?array
    {
        try {
            // If order is provided, use it first (for post-order operations)
            if ($order && $order->getId()) {
                return $this->getBillingAddressFromOrder($order);
            }

            // Fall back to quote-based logic (for initial checkout)
            return $this->getBillingAddressFromQuote();
        } catch (Exception $e) {
            $this->logger->warning(__METHOD__ . ' - Error extracting billing address: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get billing address directly from Order object
     *
     * Used for post-order operations like delayed capture, recurring payments, etc.
     *
     * @param Order $order
     * @return array|null
     */
    private function getBillingAddressFromOrder(Order $order): ?array
    {
        $billingAddress = $order->getBillingAddress();

        if (!$billingAddress) {
            $this->logger->warning(
                __METHOD__ . ' - No billing address found on order #' . $order->getIncrementId()
            );
            return null;
        }

        $streetArray = $billingAddress->getStreet();
        $telephone   = $billingAddress->getTelephone();
        $street      = !empty($streetArray) ? implode(' ', $streetArray) : '';

        if (strlen($street) > 32) {
            $street = substr($street, 0, 32);
        }

        return [
            'city'        => $billingAddress->getCity() ?: '',
            'state'       => $billingAddress->getRegionCode() ?: '',
            'postal_code' => $billingAddress->getPostcode() ?: '',
            'street'      => $street,
            'phone'       => $telephone ? preg_replace('/\D/', '', $telephone) : null,
            'country'     => $this->resolveCountryAlpha3((string)($billingAddress->getCountryId() ?? '')),
        ];
    }

    /**
     * Get billing address from quote (existing logic)
     *
     * Used for initial checkout flows
     *
     * @return array|null
     */
    private function getBillingAddressFromQuote(): ?array
    {
        $quote = $this->checkoutSession->getQuote();

        if (!$quote->getId()) {
            return null;
        }

        $billingAddress = $quote->getBillingAddress();

        // Fallback to shipping address if billing is incomplete
        if (!$billingAddress
            || !$billingAddress->getStreet()
            || !$billingAddress->getCity()
            || !$billingAddress->getPostcode()
        ) {
            $billingAddress = $quote->getShippingAddress();
        }

        // Fallback to customer's saved default billing address
        if ((!$billingAddress
                || !$billingAddress->getStreet()
                || !$billingAddress->getCity()
                || !$billingAddress->getPostcode())
            && $quote->getCustomer()
            && $quote->getCustomer()->getDefaultBilling()
        ) {
            try {
                $customerAddress = $this->addressRepository->getById(
                    $quote->getCustomer()->getDefaultBilling()
                );
                $streetArray     = $customerAddress->getStreet();
                $telephone       = $customerAddress->getTelephone();
                $street          = !empty($streetArray) ? implode(' ', $streetArray) : '';
                if (strlen($street) > 32) {
                    $street = substr($street, 0, 32);
                }

                return [
                    'city'        => $customerAddress->getCity(),
                    'state'       => $customerAddress->getRegion()->getRegionCode(),
                    'postal_code' => $customerAddress->getPostcode(),
                    'street'      => $street,
                    'phone'       => $telephone ? preg_replace('/\D/', '', $telephone) : null,
                    'country'     => $this->resolveCountryAlpha3((string)($customerAddress->getCountryId() ?? '')),
                ];
            } catch (Exception $e) {
                $this->logger->warning(
                    __METHOD__ . ' - Could not load customer default billing address: ' . $e->getMessage()
                );
                return null;
            }
        }

        // Build from quote billing/shipping address
        $streetArray = $billingAddress ? $billingAddress->getStreet() : [];
        $telephone   = $billingAddress ? $billingAddress->getTelephone() : '';
        $street      = !empty($streetArray) ? implode(' ', $streetArray) : '';
        if (strlen($street) > 32) {
            $street = substr($street, 0, 32);
        }

        return [
            'city'        => $billingAddress ? $billingAddress->getCity() : '',
            'state'       => $billingAddress ? $billingAddress->getRegionCode() : '',
            'postal_code' => $billingAddress ? $billingAddress->getPostcode() : '',
            'street'      => $street,
            'phone'       => $telephone ? preg_replace('/\D/', '', $telephone) : null,
            'country'     => $this->resolveCountryAlpha3(
                (string)($billingAddress ? $billingAddress->getCountryId() : '')
            ),
        ];
    }

    /**
     * Convert country code to ISO alpha-3 format expected by Fortis.
     *
     * @param string $countryCode
     * @return string
     */
    private function resolveCountryAlpha3(string $countryCode): string
    {
        if ($countryCode === '') {
            return '';
        }

        if (strlen($countryCode) === 3) {
            return strtoupper($countryCode);
        }

        try {
            $country = $this->countryFactory->create()->loadByCode($countryCode);

            return strtoupper((string)($country->getData('iso3_code') ?? ''));
        } catch (Exception $e) {
            $this->logger->warning(__METHOD__ . ' - Could not resolve alpha-3 country for code ' . $countryCode);

            return '';
        }
    }
}
