<?php

namespace Fortispay\Fortis\Controller\Redirect;

use Exception;
use Fortispay\Fortis\Controller\AbstractFortis;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use stdClass;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Authorise extends AbstractFortis
{
    /**
     * @var string
     */
    private $redirectToSuccessPageString;

    /**
     * @var string
     */
    private $redirectToCartPageString;

    /**
     * @var array|string[]
     */
    private array $responseCodes = [
        1500 => 'DENY',
        1510 => 'CALL',
        1520 => 'PKUP',
        1530 => 'RETRY',
        1540 => 'SETUP',
        1601 => 'GENERICFAIL',
        1602 => 'CALL',
        1603 => 'NOREPLY',
        1604 => 'PICKUP_NOFRAUD',
        1605 => 'PICKUP_FRAUD',
        1606 => 'PICKUP_LOST',
        1607 => 'PICKUP_STOLEN',
        1608 => 'ACCTERROR',
        1609 => 'ALREADY_REVERSED',
        1610 => 'BAD_PIN',
        1611 => 'CASHBACK_EXCEEDED',
        1612 => 'CASHBACK_NOAVAIL',
        1613 => 'CID_ERROR',
        1614 => 'DATE_ERROR',
        1615 => 'DO NOT HONOR',
        1616 => 'INSUFFICIENT_FUNDS',
        1617 => 'EXCEED_WITHDRAWAL_LIMIT',
        1618 => 'INVALID_SERVICE_CODE',
        1619 => 'EXCEED_ACTIVITY_LIMIT',
        1620 => 'VIOLATION',
        1621 => 'ENCRYPTION_ERROR',
        1622 => 'CARD_EXPIRED',
        1623 => 'REENTER',
        1624 => 'SECURITY_VIOLATION',
        1625 => 'NOT_PERMITTED_CARD',
        1626 => 'NOT_PERMITTED_TRAN',
        1627 => 'SYSTEM_ERROR',
        1628 => 'BAD_MERCH_ID',
        1629 => 'DUPLICATE_BATCH',
        1630 => 'REJECTED_BATCH (First attempt at batch close will fail with a transaction in the batch for $6.30. ' .
                'The second batch close attempt will succeed.)',
        1631 => 'ACCOUNT_CLOSED'
    ];

    /**
     * Execute on fortis/redirect/success
     */
    public function execute()
    {
        $json = $this->request->getContent();
        $GET  = $this->request->getParams();
        $data = json_decode($json);

        $tokenised = false;
        $orderId   = (int)$GET['gid'];
        if (!$data) {
            $tokenised = true;
            $data      = new stdClass();
            $data->id  = $GET['tid'];
        }

        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
        $this->_order = $this->_checkoutSession->getLastRealOrder();
        $order        = $this->getOrder();

        if ((int)$order->getRealOrderId() !== $orderId) {
            $this->redirectIfOrderNotFound();
        }

        $orderHistories = $order->getAllStatusHistory();
        foreach ($orderHistories as $history) {
            if ($comment = $history->getComment()) {
                if (str_starts_with($comment, 'product_transaction_id')) {
                    $product_transaction_id_order = explode(':', $comment)[1];
                }
                break;
            }
        }
        $this->pageFactory->create();
        $baseurl                           = $this->_storeManager->getStore()->getBaseUrl();
        $this->redirectToSuccessPageString = $baseurl . 'checkout/onepage/success';
        $this->redirectToCartPageString    = $baseurl . 'checkout/cart';

        $this->redirectIfOrderNotFound();

        // Get the transaction
        try {
            $api                = new FortisApi($this->config);
            $user_id            = $this->getConfigData('user_id');
            $user_api_key       = $this->getConfigData('user_api_key');
            $rawTransaction     = $api->getTransaction($data->id, $user_id, $user_api_key);
            $fortrisTransaction = $rawTransaction->data;

            if (!$tokenised && ($fortrisTransaction->product_transaction_id !== $product_transaction_id_order)) {
                throw new RuntimeException(new Phrase('Product transaction ids do not match'));
            }
            $status = $fortrisTransaction->reason_code_id;
            switch ($status) {
                case 1000:  // Success
                    // Check for stored card and save if necessary
                    $model = $this->_paymentMethod;
                    $model->saveVaultData($order, $data);

                    $status = \Magento\Sales\Model\Order::STATE_PROCESSING;
                    if ($this->getConfigData('Successful_Order_status') != "") {
                        $status = $this->getConfigData('Successful_Order_status');
                    }

                    $order_successful_email = $model->getConfigData('order_email');

                    if ($order_successful_email != '0') {
                        $this->OrderSender->send($order);
                        $order->addStatusHistoryComment(
                            __('Notified customer about order #%1.', $order->getId())
                        )->setIsCustomerNotified(true)->save();
                    }

                    // Save Transaction Response
                    $this->createTransaction($rawTransaction);

                    $order->setPaymentAuthorizationAmount($fortrisTransaction->auth_amount / 100.0);
                    $order->setPayment();

                    $order->setState($status)->setStatus($status)->save();

                    // Create third level data - dispatch event for observer
                    try {
                        $this->eventManager->dispatch(
                            'fortispay_fortis_create_third_level_data_after_success',
                            [
                                'order'                    => $order,
                                'storeManager'             => $this->_storeManager,
                                'type'                     => $fortrisTransaction->account_type,
                                'countryFactory'           => $this->countryFactory,
                                'countryCollectionFactory' => $this->countryCollectionFactory,
                                'transactionId'            => $fortrisTransaction->id,
                            ]
                        );
                    } catch (\Exception $exception) {
                        $this->_logger->error('Could not create 3rd level data: ' . $exception->getMessage());
                    }

                    if (!$tokenised) {
                        $resultJson = $this->resultJsonFactory->create();

                        return $resultJson->setData([
                                                        'redirectTo' => $this->redirectToSuccessPageString,
                                                    ]);
                    } else {
                        $redirect = $this->resultFactory->create(
                            \Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT
                        );
                        $redirect->setUrl($this->redirectToSuccessPageString);

                        return $redirect;
                    }
                    break;
                default:
                    if (isset($this->responseCodes[$status])) {
                        $message = "Not Authorised: " . $this->responseCodes[$status];
                    } else {
                        $message = 'Not Authorised: Reason Unknown';
                    }
                    $this->messageManager->addNoticeMessage($message);
                    $this->_order->addStatusToHistory(__("Failed: $message "));
                    $this->_order->cancel()->save();
                    $this->_checkoutSession->restoreQuote();
                    $this->createTransaction($data);
                    if (!$tokenised) {
                        $resultJson = $this->resultJsonFactory->create();

                        return $resultJson->setData([
                                                        'redirectTo' => $this->redirectToCartPageString,
                                                    ]);
                    } else {
                        $redirect = $this->resultFactory->create(
                            \Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT
                        );
                        $redirect->setUrl($this->redirectToCartPageString);

                        return $redirect;
                    }
                    break;
            }
        } catch (Exception $e) {
            // Save Transaction Response
            $this->createTransaction($data);
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Fortis Checkout.'));

            return $this->redirectToSuccessPageString;
        }

        return '';
    }

    /**
     * Create Transaction
     *
     * @param array $rawData
     *
     * @return int|void
     */
    public function createTransaction(stdClass $rawData)
    {
        try {
            $paymentData = $rawData->data;
            // Get payment object from order object
            $payment = $this->_order->getPayment();
            $payment->setAmountAuthorized($paymentData->transaction_amount / 100.0);
            $payment->setAmountPaid(0.00);
            $payment->setLastTransId($paymentData->id)
                    ->setTransactionId($paymentData->id)
                    ->setIsTransactionClosed(false)
                    ->setAdditionalInformation(
                        [Transaction::RAW_DETAILS => json_encode($rawData)]
                    );
            $formattedPrice = $this->_order->getBaseCurrency()->formatTxt(
                $this->_order->getGrandTotal()
            );

            $message = __('The authorized amount is %1.', $formattedPrice);
            // Get the object of builder class
            $trans       = $this->_transactionBuilder;
            $transaction = $trans->setPayment($payment)
                                 ->setOrder($this->_order)
                                 ->setTransactionId($paymentData->id)
                                 ->setAdditionalInformation(
                                     [Transaction::RAW_DETAILS => json_encode($rawData)]
                                 )
                                 ->setFailSafe(true)
                // Build method creates the transaction and returns the object
                                 ->build(TransactionInterface::TYPE_AUTH);

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId(null);
            $payment->save();
            $this->_order->save();

            return $transaction->save()->getTransactionId();
        } catch (Exception $e) {
            $this->_logger->error($e->getMessage());
        }
    }

    /**
     * Get Order By Increment Id
     *
     * @param int $incrementId
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrderByIncrementId(int $incrementId)
    {
        return $this->order->loadByIncrementId($incrementId);
    }

    /**
     * Set Last Order Details
     *
     * @return void
     */
    public function setlastOrderDetails()
    {
        $orderId      = $this->getRequest()->getParam('gid');
        $this->_order = $this->getOrderByIncrementId($orderId);
        $order        = $this->_order;
        $this->_checkoutSession->setData('last_order_id', $order->getId());
        $this->_checkoutSession->setData('last_success_quote_id', $order->getQuoteId());
        $this->_checkoutSession->setData('last_quote_id', $order->getQuoteId());
        $this->_checkoutSession->setData('last_real_order_id', $orderId);
    }

    /**
     * Get Order
     *
     * @return \Magento\Sales\Model\Order
     */
    private function getOrder()
    {
        if (!$this->_order->getId()) {
            $this->setlastOrderDetails();

            return $this->_order;
        } else {
            return $this->_order;
        }
    }

    /**
     * Redirect If Order Not Found
     *
     * @return void
     */
    private function redirectIfOrderNotFound()
    {
        if (!$this->_order->getId()) {
            // Redirect to Cart if Order not found
            return $this->redirectToSuccessPageString;
        }
    }
}
