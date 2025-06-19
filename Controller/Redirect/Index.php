<?php

namespace Fortispay\Fortis\Controller\Redirect;

use Exception;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Fortispay\Fortis\Service\CheckoutProcessor;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Index implements HttpPostActionInterface, HttpGetActionInterface
{
    public const SECURE = ['_secure' => true];
    /**
     * @var CheckoutSession $checkoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var PageFactory
     */
    private PageFactory $pageFactory;

    /**
     * @var ResultFactory
     */
    private ResultFactory $resultFactory;

    private ManagerInterface $messageManager;
    /**
     * @var FortisApi
     */
    private FortisApi $fortisApi;
    private Config $config;
    private CheckoutProcessor $checkoutProcessor;
    private UrlInterface $urlBuilder;
    private PaymentTokenManagementInterface $paymentTokenManagement;

    /**
     * @param PageFactory $pageFactory
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     * @param UrlInterface $urlBuilder
     * @param Config $config
     * @param ResultFactory $resultFactory
     * @param ManagerInterface $messageManager
     * @param PaymentTokenManagementInterface $paymentTokenManagement
     * @param FortisApi $fortisApi
     * @param CheckoutProcessor $checkoutProcessor
     */
    public function __construct(
        PageFactory $pageFactory,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        UrlInterface $urlBuilder,
        Config $config,
        ResultFactory $resultFactory,
        ManagerInterface $messageManager,
        PaymentTokenManagementInterface $paymentTokenManagement,
        FortisApi $fortisApi,
        CheckoutProcessor $checkoutProcessor,
    ) {
        $pre = __METHOD__ . " : ";

        $this->logger = $logger;

        $this->logger->debug($pre . 'bof');

        $this->checkoutSession        = $checkoutSession;
        $this->pageFactory            = $pageFactory;
        $this->resultFactory          = $resultFactory;
        $this->messageManager         = $messageManager;
        $this->fortisApi              = $fortisApi;
        $this->config                 = $config;
        $this->checkoutProcessor      = $checkoutProcessor;
        $this->urlBuilder             = $urlBuilder;
        $this->paymentTokenManagement = $paymentTokenManagement;

        $this->logger->debug($pre . 'eof');
    }

    /**
     * Execute
     */
    public function execute()
    {
        $pre = __METHOD__ . " : ";

        $page_object = $this->pageFactory->create();

        try {
            $this->checkoutProcessor->initCheckout();
        } catch (LocalizedException $e) {
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());

            return $this->checkoutProcessor->getRedirectToCartObject();
        } catch (Exception $e) {
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Fortis Checkout.'));

            return $this->checkoutProcessor->getRedirectToCartObject();
        }

        $order          = $this->checkoutSession->getLastRealOrder();
        $orderData      = $order->getPayment()->getData();
        $additionalData = $orderData['additional_information'];

        $incrementId = $order->getIncrementId();

        $action = $this->config->orderAction();

        $returnUrl = "";
        if ($action === 'sale') {
            $returnUrl = $this->urlBuilder->getUrl(
                'fortis/redirect/success',
                self::SECURE
            ) . '?gid=' . $order->getId();
        } elseif ($action === 'auth-only') {
            $returnUrl = $this->urlBuilder->getUrl(
                'fortis/redirect/authorise',
                self::SECURE
            ) . '?gid=' . $order->getId();
        }

        $vaultHash = $additionalData['fortis-vault-method'] ?? '';
        if (strlen($vaultHash) > 10) {
            // Have a vaulted card transaction
            $cardData = $this->paymentTokenManagement->getByPublicHash(
                $vaultHash,
                $order->getCustomerId()
            );

            $tokenType     = json_decode($cardData->getTokenDetails())->type;
            $gatewayToken  = $cardData->getGatewayToken();
            $surchargeData = [];
            if (isset($additionalData['fortis-surcharge-data']) && !empty($additionalData['fortis-surcharge-data'])) {
                $decodedData = json_decode($additionalData['fortis-surcharge-data'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $surchargeData = $decodedData;
                } else {
                    $this->logger->error("Error decoding surcharge data: " . json_last_error_msg());
                }
            }
            $user_id        = $this->config->userId();
            $user_api_key   = $this->config->userApiKey();
            $api            = $this->fortisApi;
            $guid           = strtoupper(Uuid::uuid4());
            $guid           = str_replace('-', '', $guid);
            $subtotalAmount = (int)bcmul((string)($order->getSubtotal() + $order->getShippingAmount()), '100', 0);
            $intentData     = [
                'transaction_amount' => (int)bcmul((string)$order->getTotalDue(), '100', 0),
                'token_id'           => $gatewayToken,
                'description'        => $incrementId,
                'transaction_api_id' => $guid,
                'subtotal_amount'    => $subtotalAmount,
                'tax'                => (int)bcmul((string)$order->getTaxAmount(), '100', 0),
            ];
            if ($tokenType === 'ach') {
                // Do the tokenised ach debit
                try {
                    $achProductId = $this->config->achProductId();
                    if ($achProductId
                        && preg_match(
                            '/^(([0-9a-fA-F]{24})|(([0-9a-fA-F]{8})(([0-9a-fA-F]{4}){3})([0-9a-fA-F]{12})))$/',
                            $achProductId
                        ) === 1) {
                        $intentData['product_transaction_id'] = $achProductId;
                    }
                    $transactionResult = $api->doAchTokenisedTransaction($intentData);
                    $transactionResult = json_decode($transactionResult);
                    if (str_contains($transactionResult->type ?? '', 'Error')
                        || isset($transactionResult->errors)
                    ) {
                        throw new LocalizedException(
                            __('Error: Please use a different saved ACH account or a new ACH account.')
                        );
                    }

                    $returnUrl .= '&tid=' . $transactionResult->data->id;
                    $redirect  = $this->resultFactory->create(
                        ResultFactory::TYPE_REDIRECT
                    );
                    $redirect->setUrl($returnUrl);

                    return $redirect;
                } catch (LocalizedException $e) {
                    $this->logger->error($e->getMessage());
                    $this->messageManager->addExceptionMessage($e, $e->getMessage());
                    $this->checkoutSession->restoreQuote();

                    return $e;
                } catch (Exception $exception) {
                    return $exception;
                }
            } else {
                // Do the tokenised card transaction
                try {
                    if (isset($surchargeData['surcharge_amount'])) {
                        $intentData['transaction_amount'] = $surchargeData['transaction_amount'];
                        $intentData['tax']                = $surchargeData['tax_amount'];
                        $intentData['surcharge_amount']   = $surchargeData['surcharge_amount'];
                        $intentData['subtotal_amount']    = $surchargeData['subtotal_amount'];
                    }

                    $productTransactionId = $this->config->ccProductId();
                    if ($productTransactionId
                        && preg_match(
                            '/^(([0-9a-fA-F]{24})|(([0-9a-fA-F]{8})(([0-9a-fA-F]{4}){3})([0-9a-fA-F]{12})))$/',
                            $productTransactionId
                        ) === 1) {
                        $intentData['product_transaction_id'] = $productTransactionId;
                    }

                    if ($action === "auth-only") {
                        $transactionResult = $api->doAuthTransaction($intentData, $user_id, $user_api_key);
                    } else {
                        $transactionResult = $api->doTokenisedTransaction($intentData, $user_id, $user_api_key);
                    }

                    $transactionResult = json_decode($transactionResult);
                    if (str_contains($transactionResult->type ?? '', 'Error')
                        || isset($transactionResult->errors)
                    ) {
                        throw new LocalizedException(__('Error: Please use a different saved card or a new card.'));
                    }

                    $returnUrl .= '&tid=' . $transactionResult->data->id;
                    $redirect  = $this->resultFactory->create(
                        ResultFactory::TYPE_REDIRECT
                    );
                    $redirect->setUrl($returnUrl);

                    return $redirect;
                } catch (LocalizedException $e) {
                    $this->logger->error($e->getMessage());
                    $this->messageManager->addExceptionMessage($e, $e->getMessage());
                    $this->checkoutSession->restoreQuote();

                    return $e;
                } catch (Exception $exception) {
                    return $exception;
                }
            }
        }

        $page_object->getLayout()
                    ->getBlock('fortis_redirect');

        return $page_object;
    }
}
