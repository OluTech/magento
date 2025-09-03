<?php

namespace Fortispay\Fortis\Controller\Api;

use Exception;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Service\CheckoutProcessor;
use InvalidArgumentException;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Vault\Model\PaymentTokenManagement;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class ProcessTokenizedPayment implements HttpPostActionInterface
{
    private JsonFactory $resultJsonFactory;
    private RequestInterface $request;
    private LoggerInterface $logger;
    private FortisApi $fortisApi;
    private Config $config;
    private CheckoutProcessor $checkoutProcessor;
    private CurrentCustomer $currentCustomer;
    private PaymentTokenManagement $paymentTokenManagement;

    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        LoggerInterface $logger,
        FortisApi $fortisApi,
        Config $config,
        CheckoutProcessor $checkoutProcessor,
        CurrentCustomer $currentCustomer,
        PaymentTokenManagement $paymentTokenManagement
    ) {
        $this->resultJsonFactory      = $resultJsonFactory;
        $this->request                = $request;
        $this->logger                 = $logger;
        $this->fortisApi              = $fortisApi;
        $this->config                 = $config;
        $this->checkoutProcessor      = $checkoutProcessor;
        $this->currentCustomer        = $currentCustomer;
        $this->paymentTokenManagement = $paymentTokenManagement;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $pre = __METHOD__ . " : ";

        try {
            $requestData = $this->request->getContent();
            $data = json_decode($requestData, true);

            $this->logger->debug($pre . "Tokenized Payment Request Data: " . json_encode($data));

            if (!isset($data['public_hash'])) {
                throw new InvalidArgumentException("Missing required parameter: public_hash");
            }

            $publicHash = $data['public_hash'];
            $customerId = $this->currentCustomer->getCustomerId();

            if (!$customerId) {
                throw new LocalizedException(__('Customer session not found'));
            }

            $cardData = $this->paymentTokenManagement->getByPublicHash($publicHash, $customerId);

            if (!$cardData) {
                throw new LocalizedException(__('Invalid payment token'));
            }

            $tokenType    = json_decode($cardData->getTokenDetails())->type;
            $gatewayToken = $cardData->getGatewayToken();

            $totals = $this->checkoutProcessor->getCheckoutTotals();

            $surchargeData = [];
            if (isset($data['surcharge_data']) && !empty($data['surcharge_data'])) {
                $surchargeData = $data['surcharge_data'];
            }

            $user_id      = $this->config->userId();
            $user_api_key = $this->config->userApiKey();
            $action       = $this->config->orderAction();

            $guid = strtoupper(Uuid::uuid4());
            $guid = str_replace('-', '', $guid);

            $subtotalAmount = (int)bcmul((string)($totals['subtotal']), '100', 0);
            $totalAmount = (int)bcmul((string)$totals['grand_total'], '100', 0);
            $taxAmount = (int)bcmul((string)$totals['tax_amount'], '100', 0);

            $intentData = [
                'transaction_amount' => $totalAmount,
                'token_id'           => $gatewayToken,
                'transaction_api_id' => $guid,
                'subtotal_amount'    => $subtotalAmount,
                'tax'                => $taxAmount,
            ];

            if ($tokenType === 'ach') {
                $achProductId = $this->config->achProductId();
                if ($achProductId && preg_match(
                    '/^(([0-9a-fA-F]{24})|(([0-9a-fA-F]{8})(([0-9a-fA-F]{4}){3})([0-9a-fA-F]{12})))$/',
                    $achProductId
                ) === 1) {
                    $intentData['product_transaction_id'] = $achProductId;
                }

                $transactionResult = $this->fortisApi->doAchTokenisedTransaction($intentData);
                $transactionResult = json_decode($transactionResult);

                if (str_contains($transactionResult->type ?? '', 'Error') || isset($transactionResult->errors)) {
                    throw new LocalizedException(
                        __('Error: Please use a different saved ACH account or a new ACH account.')
                    );
                }

                $result->setData([
                    'success' => true,
                    'transaction_id' => $transactionResult->data->id,
                    'type' => 'ach',
                    'message' => 'ACH transaction processed successfully'
                ]);

            } else {
                if (isset($surchargeData['surcharge_amount'])) {
                    $intentData['transaction_amount'] = $surchargeData['transaction_amount'];
                    $intentData['surcharge_amount']   = $surchargeData['surcharge_amount'];
                }

                $productTransactionId = $this->config->ccProductId();
                if ($productTransactionId && preg_match(
                    '/^(([0-9a-fA-F]{24})|(([0-9a-fA-F]{8})(([0-9a-fA-F]{4}){3})([0-9a-fA-F]{12})))$/',
                    $productTransactionId
                ) === 1) {
                    $intentData['product_transaction_id'] = $productTransactionId;
                }

                if ($action === "auth-only") {
                    $transactionResult = $this->fortisApi->doAuthTransaction($intentData, $user_id, $user_api_key);
                } else {
                    $transactionResult = $this->fortisApi->doTokenisedTransaction($intentData, $user_id, $user_api_key);
                }

                $transactionResult = json_decode($transactionResult);

                if (str_contains($transactionResult->type ?? '', 'Error') || isset($transactionResult->errors)) {
                    throw new LocalizedException(__('Error: Please use a different saved card or a new card.'));
                }

                $result->setData([
                    'success' => true,
                    'transaction_id' => $transactionResult->data->id,
                    'reason_code_id' => $transactionResult->data->reason_code_id,
                    'type' => 'card',
                    'action' => $action,
                    'message' => 'Card transaction processed successfully'
                ]);
            }

        } catch (LocalizedException $e) {
            $this->logger->error($pre . $e->getMessage());
            $result->setHttpResponseCode(400);
            $result->setData([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        } catch (Exception $e) {
            $this->logger->error($pre . $e->getMessage());
            $result->setHttpResponseCode(500);
            $result->setData([
                'success' => false,
                'error' => 'An unexpected error occurred. Please try again.'
            ]);
        }

        return $result;
    }
}
