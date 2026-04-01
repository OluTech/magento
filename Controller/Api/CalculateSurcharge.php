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
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Vault\Model\PaymentTokenManagement;
use Psr\Log\LoggerInterface;

class CalculateSurcharge implements HttpGetActionInterface
{
    private JsonFactory $resultJsonFactory;
    private RequestInterface $request;
    private LoggerInterface $logger;
    private FortisApi $fortisApi;
    private Config $config;
    private CheckoutProcessor $checkoutProcessor;
    private CurrentCustomer $currentCustomer;
    private PaymentTokenManagement $paymentTokenManagement;
    private SessionManagerInterface $sessionManager;

    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        LoggerInterface $logger,
        FortisApi $fortisApi,
        CheckoutProcessor $checkoutProcessor,
        CurrentCustomer $currentCustomer,
        PaymentTokenManagement $paymentTokenManagement,
        Config $config,
        SessionManagerInterface $sessionManager
    ) {
        $this->resultJsonFactory      = $resultJsonFactory;
        $this->request                = $request;
        $this->logger                 = $logger;
        $this->fortisApi              = $fortisApi;
        $this->checkoutProcessor      = $checkoutProcessor;
        $this->currentCustomer        = $currentCustomer;
        $this->paymentTokenManagement = $paymentTokenManagement;
        $this->config                 = $config;
        $this->sessionManager         = $sessionManager;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            // Preserve session state before processing long-running surcharge calculation
            // This prevents "http_user_agent" session validation errors that occur when
            // session expires during the API call to Fortis
            $this->preserveSessionState();

            $requestData = $this->request->getParams();

            $this->logger->info("Calculate Surcharge Request Data: " . json_encode($requestData));

            if (!isset($requestData['public_hash']) && !isset($requestData['ticket_id'])) {
                throw new InvalidArgumentException("Missing required parameters.");
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
                throw new InvalidArgumentException("Invalid parameters provided.");
            }

            $surchargeData = $this->fortisApi->calculateSurcharge($intentData);

            if (empty($surchargeData)) {
                throw new \Exception("Failed to calculate surcharge: Empty response from API");
            }

            $this->logger->info("Calculated Data: " . $surchargeData);

            $surchargeDataArray = json_decode($surchargeData, true);
            if (!is_array($surchargeDataArray) || !isset($surchargeDataArray['data'])) {
                throw new \Exception("Invalid surcharge data format");
            }
            $result->setData(['surchargeData' => $surchargeData]);
        } catch (LocalizedException $e) {
            $this->logger->error($e->getMessage());
            $result->setHttpResponseCode(400);
            $result->setData(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error($e);
            $result->setHttpResponseCode(500);
            $result->setData(['error' => $e->getMessage()]);
        } finally {
            // Validate and refresh session after surcharge calculation completes
            // Ensures session remains valid for subsequent checkout operations
            $this->validateAndRefreshSession();
        }

        return $result;
    }

    /**
     * Preserve session state to prevent validation errors during API calls
     * Regenerates session ID and writes session data to storage
     *
     * @return void
     */
    private function preserveSessionState(): void
    {
        try {
            if ($this->sessionManager->isSessionExists()) {
                // Regenerate session ID to refresh the session validity window
                // This prevents "http_user_agent" validation errors during checkout
                $this->sessionManager->regenerateId();

                // Write session data to storage to ensure state persistence
                $this->sessionManager->writeClose();

                $this->logger->debug(
                    'CalculateSurcharge: Session state preserved. Session ID: ' .
                    $this->sessionManager->getSessionId()
                );
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'CalculateSurcharge: Failed to preserve session state: ' . $e->getMessage() .
                '. Continuing with request.'
            );
        }
    }

    /**
     * Validate and refresh session after API operations complete
     * Ensures session remains valid for subsequent checkout steps
     *
     * @return void
     */
    private function validateAndRefreshSession(): void
    {
        try {
            if ($this->sessionManager->isSessionExists()) {
                // Restart session to ensure cookies and data are current
                $this->sessionManager->start();

                $this->logger->debug(
                    'CalculateSurcharge: Session validated and refreshed. Session ID: ' .
                    $this->sessionManager->getSessionId()
                );
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'CalculateSurcharge: Failed to validate session: ' . $e->getMessage() .
                '. Session may be invalid.'
            );
        }
    }
}
