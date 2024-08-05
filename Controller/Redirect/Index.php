<?php

namespace Fortispay\Fortis\Controller\Redirect;

use Exception;
use Fortispay\Fortis\Controller\AbstractFortis;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Ramsey\Uuid\Uuid;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Index extends AbstractFortis
{
    public const SECURE = ['_secure' => true];
    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * Config method type
     *
     * @var string
     */
    protected $_configMethod = Config::METHOD_CODE;

    /**
     * Execute
     */
    public function execute()
    {
        $pre = __METHOD__ . " : ";

        $page_object = $this->pageFactory->create();

        try {
            $this->_initCheckout();
        } catch (LocalizedException $e) {
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());

            return $this->getRedirectToCartObject();
        } catch (Exception $e) {
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Fortis Checkout.'));

            return $this->getRedirectToCartObject();
        }

        $order          = $this->_checkoutSession->getLastRealOrder();
        $orderData      = $order->getPayment()->getData();
        $additionalData = $orderData['additional_information'];

        $incrementId = $order->getIncrementId();

        $action = $this->config->orderAction();

        $returnUrl = "";
        if ($action === 'sale') {
            $returnUrl = $this->_urlBuilder->getUrl(
                'fortis/redirect/success',
                self::SECURE
            ) . '?gid=' . $order->getRealOrderId();
        } elseif ($action === 'auth-only') {
            $returnUrl = $this->_urlBuilder->getUrl(
                'fortis/redirect/authorise',
                self::SECURE
            ) . '?gid=' . $order->getRealOrderId();
        }

        $vaultHash = $additionalData['fortis-vault-method'] ?? '';
        if (strlen($vaultHash) > 10) {
            // Have a vaulted card transaction
            $paymentTokenManagementInterface = $this->_paymentMethod->getPaymentTokenManagement();
            $cardData                        = $paymentTokenManagementInterface->getByPublicHash(
                $vaultHash,
                $order->getCustomerId()
            );

            $tokenType    = json_decode($cardData->getTokenDetails())->type;
            $gatewayToken = $cardData->getGatewayToken();
            $user_id      = $this->config->userId();
            $user_api_key = $this->config->userApiKey();
            $api          = new FortisApi($this->config);
            $guid         = strtoupper(Uuid::uuid4());
            $guid         = str_replace('-', '', $guid);
            $intentData   = [
                'transaction_amount' => (int)($order->getTotalDue() * 100),
                'token_id'           => $gatewayToken,
                'description'        => $incrementId,
                'transaction_api_id' => $guid,
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
                        \Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT
                    );
                    $redirect->setUrl($returnUrl);

                    $this->successURL = $returnUrl;

                    return $redirect;
                } catch (LocalizedException $e) {
                    $this->_logger->error($e->getMessage());
                    $this->messageManager->addExceptionMessage($e, $e->getMessage());
                    $this->_checkoutSession->restoreQuote();

                    return $e;
                } catch (Exception $exception) {
                    return $exception;
                }
            } else {
                // Do the tokenised card transaction
                try {
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
                        \Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT
                    );
                    $redirect->setUrl($returnUrl);

                    $this->successURL = $returnUrl;

                    return $redirect;
                } catch (LocalizedException $e) {
                    $this->_logger->error($e->getMessage());
                    $this->messageManager->addExceptionMessage($e, $e->getMessage());
                    $this->setMessage($e->getMessage());
                    $this->_checkoutSession->restoreQuote();

                    return $e;
                } catch (Exception $exception) {
                    return $exception;
                }
            }
        }

        $block = $page_object->getLayout()
                             ->getBlock('fortis_redirect')
                             ->setPaymentFormData($order);

        $formData = $block->getSubmitForm();
        if (!$formData) {
            $this->_logger->error("We can\'t start Fortis Checkout.");

            return $this->getRedirectToCartObject();
        }

        return $page_object;
    }

    public function getResponse()
    {
        return $this->getResponse();
    }
}
