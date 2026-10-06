<?php

namespace Fortispay\Fortis\Controller\Api;

use Fortispay\Fortis\Service\FortisMethodService;
use Fortispay\Fortis\Service\CheckoutProcessor;
use Fortispay\Fortis\Service\RateLimiter;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Customer\Api\AddressRepositoryInterface;
use Fortispay\Fortis\Model\Config;

class TicketTransaction implements HttpPostActionInterface
{
    private const RATE_LIMIT_ACTION = 'tickettransaction';

    private const RATE_LIMIT_MAX_ATTEMPTS = 10;

    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * Maximum age, in seconds, of a cached surcharge calculation before it is considered stale.
     */
    private const VERIFIED_SURCHARGE_MAX_AGE_SECONDS = 900;

    private const USED_TICKET_SESSION_KEY = 'fortis_used_ticket_id';

    private const ATTEMPT_COUNT_SESSION_KEY = 'fortis_ticket_attempt_count';

    private const DECLINE_COUNT_SESSION_KEY = 'fortis_ticket_decline_count';

    private const MAX_ATTEMPTS = 5;

    private const MAX_CONSECUTIVE_DECLINES = 3;

    private const ATTEMPT_COUNT_TTL_SECONDS = 900;

    /**
     * @var JsonFactory
     */
    private JsonFactory $resultJsonFactory;

    /**
     * @var FortisMethodService
     */
    private FortisMethodService $fortisMethodService;

    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var AddressRepositoryInterface
     */
    private AddressRepositoryInterface $addressRepository;

    /**
     * @var CheckoutProcessor
     */
    private CheckoutProcessor $checkoutProcessor;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var RateLimiter
     */
    private RateLimiter $rateLimiter;

    /**
     * @param JsonFactory $resultJsonFactory
     * @param FortisMethodService $fortisMethodService
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     * @param RequestInterface $request
     * @param AddressRepositoryInterface $addressRepository
     * @param CheckoutProcessor $checkoutProcessor
     * @param Config $config
     * @param RateLimiter $rateLimiter
     */
    public function __construct(
        JsonFactory $resultJsonFactory,
        FortisMethodService $fortisMethodService,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        RequestInterface $request,
        AddressRepositoryInterface $addressRepository,
        CheckoutProcessor $checkoutProcessor,
        Config $config,
        RateLimiter $rateLimiter
    ) {
        $this->resultJsonFactory   = $resultJsonFactory;
        $this->fortisMethodService = $fortisMethodService;
        $this->checkoutSession     = $checkoutSession;
        $this->logger              = $logger;
        $this->request             = $request;
        $this->addressRepository   = $addressRepository;
        $this->checkoutProcessor   = $checkoutProcessor;
        $this->config              = $config;
        $this->rateLimiter         = $rateLimiter;
    }

    /**
     * Create transaction from a Fortis ticket intention.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->rateLimiter->isAllowed(
            self::RATE_LIMIT_ACTION,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS
        )) {
            $resultJson->setHttpResponseCode(429);
            return $resultJson->setData([
                'success' => false,
                'error'   => __('Too many requests. Please wait a moment and try again.'),
            ]);
        }

        try {
            $payload = json_decode($this->request->getContent(), true);

            if (!isset($payload['ticketIntention']['id'])) {
                throw new LocalizedException(__('Missing ticketIntention'));
            }

            $ticketIntention     = $payload['ticketIntention'];
            $enableVaultForOrder = $payload['fortisVault'] ?? false;

            $quote = $this->checkoutSession->getQuote();

            $usedTicket = $this->checkoutSession->getData(self::USED_TICKET_SESSION_KEY);
            if (is_array($usedTicket)
                && (int)($usedTicket['quote_id'] ?? 0) === (int)$quote->getId()
                && ($usedTicket['ticket_id'] ?? null) === $ticketIntention['id']
            ) {
                throw new LocalizedException(__('This payment has already been processed.'));
            }

            if (!$quote->getId()) {
                throw new LocalizedException(
                    __('Your session has expired. Please refresh the page and try again.')
                );
            }

            $attemptState = $this->checkoutSession->getData(self::ATTEMPT_COUNT_SESSION_KEY);
            $declineState = $this->checkoutSession->getData(self::DECLINE_COUNT_SESSION_KEY);
            $attemptStateIsCurrent = is_array($attemptState)
                && (int)($attemptState['quote_id'] ?? 0) === (int)$quote->getId()
                && (time() - (int)($attemptState['updated_at'] ?? 0)) < self::ATTEMPT_COUNT_TTL_SECONDS;
            $attemptCount = $attemptStateIsCurrent ? (int)($attemptState['count'] ?? 0) : 0;
            $declineCount = $attemptStateIsCurrent && is_array($declineState)
                && (int)($declineState['quote_id'] ?? 0) === (int)$quote->getId()
                ? (int)($declineState['count'] ?? 0) : 0;
            if ($attemptCount >= self::MAX_ATTEMPTS || $declineCount >= self::MAX_CONSECUTIVE_DECLINES) {
                $message = 'Too many payment attempts. Please refresh checkout and try again in ' . ceil(self::ATTEMPT_COUNT_TTL_SECONDS / 60) . ' minutes.';
                throw new LocalizedException(__($message));
            }

            $this->checkoutSession->setData(self::ATTEMPT_COUNT_SESSION_KEY, [
                'quote_id' => (int)$quote->getId(),
                'count' => $attemptCount + 1,
                'updated_at' => time(),
            ]);

            $surchargeData = $this->getVerifiedSurchargeData($ticketIntention['id'], $quote->getQuoteCurrencyCode());

            $billingAddress = $quote->getBillingAddress();

            if (!$billingAddress ||
                !$billingAddress->getStreet() ||
                !$billingAddress->getCity() ||
                !$billingAddress->getPostcode()
            ) {
                $billingAddress = $quote->getShippingAddress();
            }

            if ((!$billingAddress || !$billingAddress->getStreet() || !$billingAddress->getCity(
            ) || !$billingAddress->getPostcode())
                && $quote->getCustomer() && $quote->getCustomer()->getDefaultBilling()
            ) {
                $customer  = $quote->getCustomer();
                $addressId = $customer->getDefaultBilling();
                try {
                    $address     = $this->addressRepository->getById($addressId);
                    $streetArray = $address->getStreet();
                    $telephone   = $address->getTelephone();
                    $street      = !empty($streetArray) ? implode(' ', $streetArray) : '';
                    if (strlen($street) > 32) {
                        $street = substr($street, 0, 32);
                    }
                    $billingInfo = [
                        'city'        => $address->getCity(),
                        'state'       => $address->getRegion()->getRegionCode(),
                        'postal_code' => $address->getPostcode(),
                        'street'      => $street,
                        'phone'       => $telephone ? preg_replace('/\D/', '', $telephone) : null
                    ];
                } catch (\Exception $e) {
                    $billingInfo = [
                        'city'        => '',
                        'state'       => '',
                        'postal_code' => '',
                        'street'      => '',
                        'phone'       => null
                    ];
                }
            } else {
                $streetArray = $billingAddress ? $billingAddress->getStreet() : [];
                $telephone   = $billingAddress && $billingAddress->getTelephone() ? $billingAddress->getTelephone(
                ) : '';
                $street      = !empty($streetArray) ? implode(' ', $streetArray) : '';
                if (strlen($street) > 32) {
                    $street = substr($street, 0, 32);
                }
                $billingInfo = [
                    'city'        => $billingAddress ? $billingAddress->getCity() : '',
                    'state'       => $billingAddress ? $billingAddress->getRegion() : '',
                    'postal_code' => $billingAddress ? $billingAddress->getPostcode() : '',
                    'phone'       => $telephone ? preg_replace('/\D/', '', $telephone) : null,
                    'street'      => $street,
                ];
            }

            $quoteCurrency = $quote->getQuoteCurrencyCode();

            if (!$this->config->isCurrencySupported($quoteCurrency)) {
                $supportedCurrencies = implode(', ', $this->config->getSupportedCurrencies());
                throw new LocalizedException(
                    __(
                        'Currency "%1" is not supported. Please select one of the supported currencies: %2',
                        $quoteCurrency,
                        $supportedCurrencies
                    )
                );
            }

            $totals = $this->checkoutProcessor->getCheckoutTotals();

            $totals = [
                'subtotal_amount'    => (int)bcmul((string)$totals['subtotal'], '100', 0),
                'tax'                => (int)bcmul((string)$totals['tax_amount'], '100', 0),
                'transaction_amount' => (int)bcmul((string)$totals['grand_total'], '100', 0),
                'currency'           => $quoteCurrency
            ];

            if ($surchargeData && isset($surchargeData['surcharge_amount'])) {
                $totals['subtotal_amount'] = (int)$surchargeData['subtotal_amount'];
                $totals['surcharge_amount'] = (int)$surchargeData['surcharge_amount'];
                $totals['transaction_amount'] = (int)$surchargeData['transaction_amount'];
            }

            $locationId = $this->config->achLocationId();
            if ($locationId === '') {
                throw new LocalizedException(__('Fortis credit card location is not configured.'));
            }

            $ticketIntention['order_id'] = $quote->getReservedOrderId() ?? $quote->getId();
            $ticketIntention['location_id'] = $locationId;

            $enableVaultForOrder = $enableVaultForOrder === 'new-save' || $enableVaultForOrder === 1;

            $ticketTransaction = $this->fortisMethodService->createTicketTransaction(
                $ticketIntention,
                $totals,
                $billingInfo,
                $enableVaultForOrder
            );

            if ($this->isSuccessfulTransaction($ticketTransaction)) {
                $this->checkoutSession->setData(self::USED_TICKET_SESSION_KEY, [
                    'quote_id' => (int)$quote->getId(),
                    'ticket_id' => $ticketIntention['id'],
                ]);
                $this->checkoutSession->unsetData(CalculateSurcharge::VERIFIED_SURCHARGE_SESSION_KEY);
                $this->checkoutSession->unsetData(self::ATTEMPT_COUNT_SESSION_KEY);
                $this->checkoutSession->unsetData(self::DECLINE_COUNT_SESSION_KEY);
            } else {
                $this->checkoutSession->setData(self::DECLINE_COUNT_SESSION_KEY, [
                    'quote_id' => (int)$quote->getId(),
                    'count' => $declineCount + 1,
                    'updated_at' => time(),
                ]);
            }

            return $resultJson->setData([
                                            'success' => true,
                                            'data'    => $ticketTransaction->data ?? $ticketTransaction,
                                        ]);
        } catch (LocalizedException $e) {
            $this->logger->error('TicketTransaction error: ' . $e->getMessage());

            return $resultJson->setData([
                                            'success' => false,
                                            'error'   => $e->getMessage(),
                                        ]);
        } catch (\Exception $e) {
            $this->logger->error('TicketTransaction error: ' . $e->getMessage());

            return $resultJson->setData([
                                            'success' => false,
                                            'error'   => $e->getMessage(),
                                        ]);
        }
    }

    /**
     * Look up the server-computed surcharge for this exact ticket_id.
     *
     * Reads the value cached by CalculateSurcharge rather than trusting a client-supplied payload.
     *
     * @param string $ticketId
     * @param string|null $currency
     * @return array|null
     */
    private function getVerifiedSurchargeData(string $ticketId, ?string $currency): ?array
    {
        $cached = $this->checkoutSession->getData(CalculateSurcharge::VERIFIED_SURCHARGE_SESSION_KEY);

        if (!is_array($cached)
            || ($cached['ticket_id'] ?? null) !== $ticketId
            || ($cached['currency'] ?? null) !== $currency
            || !isset(
                $cached['computed_at'],
                $cached['data']['subtotal_amount'],
                $cached['data']['surcharge_amount'],
                $cached['data']['transaction_amount']
            )
            || (time() - (int)$cached['computed_at']) > self::VERIFIED_SURCHARGE_MAX_AGE_SECONDS
        ) {
            return null;
        }

        return $cached['data'];
    }

    private function isSuccessfulTransaction(object $transaction): bool
    {
        $response = $transaction->data ?? $transaction;

        return isset($response->reason_code_id) && (int)$response->reason_code_id === 1000;
    }
}
