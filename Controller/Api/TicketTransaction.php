<?php

namespace Fortispay\Fortis\Controller\Api;

use Fortispay\Fortis\Service\FortisMethodService;
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

class TicketTransaction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private JsonFactory $resultJsonFactory;
    private FortisMethodService $fortisMethodService;
    private QuoteRepository $quoteRepository;
    private CheckoutSession $checkoutSession;
    private LoggerInterface $logger;
    private RequestInterface $request;
    private AddressRepositoryInterface $addressRepository;

    public function __construct(
        JsonFactory $resultJsonFactory,
        FortisMethodService $fortisMethodService,
        QuoteRepository $quoteRepository,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        RequestInterface $request,
        AddressRepositoryInterface $addressRepository
    ) {
        $this->resultJsonFactory   = $resultJsonFactory;
        $this->fortisMethodService = $fortisMethodService;
        $this->quoteRepository     = $quoteRepository;
        $this->checkoutSession     = $checkoutSession;
        $this->logger              = $logger;
        $this->request             = $request;
        $this->addressRepository   = $addressRepository;
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
                        'phone'       => preg_replace('/\D/', '', $telephone)
                    ];
                } catch (\Exception $e) {
                    $billingInfo = [
                        'city'        => '',
                        'state'       => '',
                        'postal_code' => '',
                        'street'      => '',
                        'phone'       => ''
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
                    'street'      => $street,
                ];

                if ($telephone) {
                    $billingInfo['phone'] = preg_replace('/\D/', '', $telephone);
                }
            }

            $totals = [
                'subtotal_amount'    => (int)bcmul(
                    (string)($quote->getSubtotal() + $quote->getShippingAddress()->getShippingAmount()),
                    '100',
                    0
                ),
                'tax'                => (int)bcmul((string)$quote->getShippingAddress()->getTaxAmount(), '100', 0),
                'transaction_amount' => (int)bcmul((string)$quote->getGrandTotal(), '100', 0)
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
