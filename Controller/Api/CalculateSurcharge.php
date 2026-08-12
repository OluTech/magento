<?php

namespace Fortispay\Fortis\Controller\Api;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Service\CheckoutProcessor;
use InvalidArgumentException;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Vault\Model\PaymentTokenManagement;
use Psr\Log\LoggerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;

class CalculateSurcharge implements HttpGetActionInterface
{
    /**
     * @var JsonFactory
     */
    private JsonFactory $resultJsonFactory;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var FortisApi
     */
    private FortisApi $fortisApi;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var CheckoutProcessor
     */
    private CheckoutProcessor $checkoutProcessor;

    /**
     * @var CurrentCustomer
     */
    private CurrentCustomer $currentCustomer;

    /**
     * @var PaymentTokenManagement
     */
    private PaymentTokenManagement $paymentTokenManagement;

    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @param JsonFactory $resultJsonFactory
     * @param RequestInterface $request
     * @param LoggerInterface $logger
     * @param FortisApi $fortisApi
     * @param CheckoutProcessor $checkoutProcessor
     * @param CurrentCustomer $currentCustomer
     * @param PaymentTokenManagement $paymentTokenManagement
     * @param CheckoutSession $checkoutSession
     * @param Config $config
     */
    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        LoggerInterface $logger,
        FortisApi $fortisApi,
        CheckoutProcessor $checkoutProcessor,
        CurrentCustomer $currentCustomer,
        PaymentTokenManagement $paymentTokenManagement,
        CheckoutSession $checkoutSession,
        Config $config
    ) {
        $this->resultJsonFactory      = $resultJsonFactory;
        $this->request                = $request;
        $this->logger                 = $logger;
        $this->fortisApi              = $fortisApi;
        $this->checkoutProcessor      = $checkoutProcessor;
        $this->currentCustomer        = $currentCustomer;
        $this->paymentTokenManagement = $paymentTokenManagement;
        $this->checkoutSession        = $checkoutSession;
        $this->config                 = $config;
    }

    /**
     * Calculate surcharge for tokenized or ticket-based payment.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        // Release session lock before blocking Fortis API call.
        // Prevents concurrent checkout AJAX requests from being blocked.
        $this->checkoutSession->writeClose();
        try {
            $requestData = $this->request->getParams();

            $this->logger->info("Calculate Surcharge Request Data: " . json_encode($requestData));

            if (!isset($requestData['public_hash']) && !isset($requestData['ticket_id'])) {
                throw new LocalizedException(__('Missing required parameters.'));
            }

            $totals     = $this->checkoutProcessor->getCheckoutTotals();
            $postalCode = $this->checkoutProcessor->getCurrentBillingPostalCode();
            $currency   = $this->checkoutProcessor->getCheckoutCurrency();

            if (!$this->config->isCurrencySupported($currency)) {
                $supportedCurrencies = implode(', ', $this->config->getSupportedCurrencies());
                throw new LocalizedException(
                    __(
                        'Currency "%1" is not supported. Please select one of the supported currencies: %2',
                        $currency,
                        $supportedCurrencies
                    )
                );
            }

            $intentData = [
                'subtotal_amount'        => (int)bcmul((string)$totals['subtotal'], '100', 0),
                'tax_amount'             => (int)bcmul((string)$totals['tax_amount'], '100', 0),
                'zip'                    => $postalCode,
                'product_transaction_id' => $this->config->getProductIdForCurrency($currency)
            ];

            if (isset($requestData['public_hash'])) {
                $publicHash             = $requestData['public_hash'];
                $customerId             = $this->currentCustomer->getCustomerId();
                $card                   = $this->paymentTokenManagement->getByPublicHash($publicHash, $customerId);
                $intentData['token_id'] = $card['gateway_token'];
            } elseif (isset($requestData['ticket_id'])) {
                $intentData['ticket_id'] = $requestData['ticket_id'];
            } else {
                throw new LocalizedException(__('Invalid parameters provided.'));
            }

            $surchargeData = $this->fortisApi->calculateSurcharge($intentData);

            if (empty($surchargeData)) {
                throw new LocalizedException(__('Failed to calculate surcharge: empty response from API.'));
            }

            $this->logger->info("Calculated Data: " . $surchargeData);

            $surchargeDataArray = json_decode($surchargeData, true);
            if (!is_array($surchargeDataArray) || !isset($surchargeDataArray['data'])) {
                throw new LocalizedException(__('Invalid surcharge data format.'));
            }
            $result->setData(['surchargeData' => $surchargeData]);
        } catch (LocalizedException $e) {
            $this->logger->error($e->getMessage());
            $result->setHttpResponseCode(400);
            $result->setData(['error' => $e->getMessage()]);
        }

        return $result;
    }
}
