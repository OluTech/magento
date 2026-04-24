<?php

namespace Fortispay\Fortis\Controller\Api;

use Fortispay\Fortis\Service\FortisMethodService;
use Fortispay\Fortis\Service\CheckoutProcessor;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Model\QuoteRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Customer\Api\AddressRepositoryInterface;
use Fortispay\Fortis\Model\Config;

class TicketTransaction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private JsonFactory $resultJsonFactory;
    private FortisMethodService $fortisMethodService;
    private QuoteRepository $quoteRepository;
    private CheckoutSession $checkoutSession;
    private LoggerInterface $logger;
    private RequestInterface $request;
    private AddressRepositoryInterface $addressRepository;
    private CheckoutProcessor $checkoutProcessor;
    private Config $config;

    public function __construct(
        JsonFactory $resultJsonFactory,
        FortisMethodService $fortisMethodService,
        QuoteRepository $quoteRepository,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        RequestInterface $request,
        AddressRepositoryInterface $addressRepository,
        CheckoutProcessor $checkoutProcessor,
        Config $config
    ) {
        $this->resultJsonFactory   = $resultJsonFactory;
        $this->fortisMethodService = $fortisMethodService;
        $this->quoteRepository     = $quoteRepository;
        $this->checkoutSession     = $checkoutSession;
        $this->logger              = $logger;
        $this->request             = $request;
        $this->addressRepository   = $addressRepository;
        $this->checkoutProcessor   = $checkoutProcessor;
        $this->config              = $config;
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        try {
            $payload = json_decode($this->request->getContent(), true);

            if (!isset($payload['ticketIntention']['id'])) {
                throw new LocalizedException(__('Missing ticketIntention'));
            }

            $ticketIntention     = $payload['ticketIntention'];
            $surchargeData       = $payload['surchargeData'] ?? null;
            $enableVaultForOrder = $payload['fortisVault'] ?? false;

            $quote = $this->checkoutSession->getQuote();
            // Session may be invalid — fall back to quote lookup via reserved order ID
            if (!$quote->getId() && !empty($payload['order_id'])) {
                try {
                    $criteria = $this->checkoutProcessor->getSearchCriteriaBuilder()
                        ->addFilter('reserved_order_id', $payload['order_id'])
                        ->create();
                    $results  = $this->quoteRepository->getList($criteria)->getItems();
                    if (!empty($results)) {
                        $quote = reset($results);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('TicketTransaction: fallback quote lookup failed: ' . $e->getMessage());
                }
            }

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

            $ticketIntention['order_id'] = $quote->getReservedOrderId() ?? $quote->getId();

            $enableVaultForOrder = $enableVaultForOrder === 'new-save' || $enableVaultForOrder === 1;

            $ticketTransaction = $this->fortisMethodService->createTicketTransaction(
                $ticketIntention,
                $totals,
                $billingInfo,
                $enableVaultForOrder,
                $surchargeData
            );

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
     * @inheritDoc
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
