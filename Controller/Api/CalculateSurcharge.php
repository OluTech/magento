<?php

namespace Fortispay\Fortis\Controller\Api;

use Fortispay\Fortis\Service\CheckoutProcessor;
use InvalidArgumentException;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\App\CsrfTokenInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Vault\Model\PaymentTokenManagement;
use Psr\Log\LoggerInterface;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\App\Request\CsrfValidator;

class CalculateSurcharge implements HttpGetActionInterface
{
    private JsonFactory $resultJsonFactory;
    private RequestInterface $request;
    private LoggerInterface $logger;
    private FortisApi $fortisApi;
    private CheckoutProcessor $checkoutProcessor;
    private CurrentCustomer $currentCustomer;
    private PaymentTokenManagement $paymentTokenManagement;

    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        LoggerInterface $logger,
        FortisApi $fortisApi,
        CheckoutProcessor $checkoutProcessor,
        CurrentCustomer $currentCustomer,
        PaymentTokenManagement $paymentTokenManagement
    ) {
        $this->resultJsonFactory      = $resultJsonFactory;
        $this->request                = $request;
        $this->logger                 = $logger;
        $this->fortisApi              = $fortisApi;
        $this->checkoutProcessor      = $checkoutProcessor;
        $this->currentCustomer        = $currentCustomer;
        $this->paymentTokenManagement = $paymentTokenManagement;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $requestData = $this->request->getParams();

            $this->logger->info("Calculate Surcharge Request Data: " . json_encode($requestData));

            if (!isset($requestData['public_hash']) && !isset($requestData['ticket_id'])) {
                throw new InvalidArgumentException("Missing required parameters.");
            }

            $totals     = $this->checkoutProcessor->getCheckoutTotals();
            $postalCode = $this->checkoutProcessor->getCurrentBillingPostalCode();

            $intentData = [
                'subtotal_amount' => (int)bcmul((string)$totals['subtotal'], '100', 0),
                'tax_amount'      => (int)bcmul((string)$totals['tax_amount'], '100', 0),
                'zip'             => $postalCode
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
        } catch (\Exception $e) {
            $this->logger->error($e);
            $result->setHttpResponseCode(500);
            $result->setData(['error' => $e->getMessage()]);
        }

        return $result;
    }
}
