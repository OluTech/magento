<?php

namespace Fortis\Fortis\Controller\Redirect;

use Exception;
use Fortis\Fortis\Controller\AbstractFortis;
use Fortis\Fortis\Model\FortisApi;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Success extends AbstractFortis
{
    /**
     * @var string
     */
    private $redirectToSuccessPageString;

    /**
     * @var string
     */
    private $redirectToCartPageString;

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
        1630 => 'REJECTED_BATCH (First attempt at batch close will fail with a transaction in the batch for $6.30. The second batch close attempt will succeed.)',
        1631 => 'ACCOUNT_CLOSED'
    ];

    /**
     * Execute on fortis/redirect/success
     */
    public function execute()
    {
        $data      = json_decode(file_get_contents('php://input'));
        $tokenised = false;
        $orderId   = (int)$_GET['gid'];
        if (!$data) {
            $tokenised = true;
            $data      = new \stdClass();
            $data->id  = $_GET['tid'];
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
                if (strpos($comment, 'product_transaction_id') === 0) {
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
            $api               = new FortisApi($this->getConfigData('fortis_environment'));
            $user_id           = $this->getConfigData('user_id');
            $user_api_key      = $this->getConfigData('user_api_key');
            $fortisTransaction = $api->getTransaction($data->id, $user_id, $user_api_key)->data;

            if (!$tokenised && ($fortisTransaction->product_transaction_id !== $product_transaction_id_order)) {
                throw new \Exception('Product transaction ids do not match');
            }
            $status = $fortisTransaction->status_code;
            switch ($status) {
                case 101:  // Success
                case 102:  // Success
                    // Check for stored card and save if necessary
                    $model = $this->_paymentMethod;
                    $model->saveVaultData($order, $data);

                    $status = \Magento\Sales\Model\Order::STATE_PROCESSING;
                    if ($this->getConfigData('Successful_Order_status') != "") {
                        $status = $this->getConfigData('Successful_Order_status');
                    }

                    $model                  = $this->_paymentMethod;
                    $order_successful_email = $model->getConfigData('order_email');

                    if ($order_successful_email != '0') {
                        $this->OrderSender->send($order);
                        $order->addStatusHistoryComment(
                            __('Notified customer about order #%1.', $order->getId())
                        )->setIsCustomerNotified(true)->save();
                    }

                    // Capture invoice when payment is successful
                    $invoice = $this->_invoiceService->prepareInvoice($order);
                    $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                    $invoice->register();

                    // Save the invoice to the order
                    $fortisTransaction = $this->dbTransaction
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder());

                    $fortisTransaction->save();

                    // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                    $send_invoice_email = $model->getConfigData('invoice_email');
                    if ($send_invoice_email != '0') {
                        $this->invoiceSender->send($invoice);
                        $order->addStatusHistoryComment(
                            __('Notified customer about invoice #%1.', $invoice->getId())
                        )->setIsCustomerNotified(true)->save();
                    }

                    // Save Transaction Response
                    $transactionId = $this->createTransaction($data);

                    $invoice->setTransactionId($transactionId);
                    $invoice->save();

                    $order->setState($status)->setStatus($status)->save();
                    // Invoice capture code completed
                    if (!$tokenised) {
                        echo json_encode(
                            ['redirectTo' => $this->redirectToSuccessPageString]
                        );
                        exit;
                    } else {
                        header("Location:" . $this->redirectToSuccessPageString);
                        exit;
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
                        echo json_encode(
                            ['redirectTo' => $this->redirectToCartPageString]
                        );
                        exit;
                    } else {
                        header("Location:" . $this->redirectToCartPageString);
                        exit;
                    }
                    exit;
                    break;
            }
        } catch (Exception $e) {
            // Save Transaction Response
            $this->createTransaction($data);
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Fortis Checkout.'));
            echo $this->redirectToSuccessPageString;
        }

        return '';
    }

    /**
     * @param $paymentData
     *
     * @return int|void
     */
    public function createTransaction($paymentData = [])
    {
        try {
            // Get payment object from order object
            $payment = $this->_order->getPayment();
            $payment->setLastTransId($paymentData->id)
                    ->setTransactionId($paymentData->id)
                    ->setAdditionalInformation(
                        [Transaction::RAW_DETAILS => json_encode($paymentData)]
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
                                     [Transaction::RAW_DETAILS => json_encode($paymentData)]
                                 )
                                 ->setFailSafe(true)
                // Build method creates the transaction and returns the object
                                 ->build(Transaction::TYPE_CAPTURE);

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

    public function getOrderByIncrementId($incrementId)
    {
        return $this->order->loadByIncrementId($incrementId);
    }

    public function setlastOrderDetails()
    {
        $orderId      = $this->getRequest()->getParam('gid');
        $this->_order = $this->getOrderByIncrementId($orderId);
        $order        = $this->_order;
        $this->_checkoutSession->setData('last_order_id', $order->getId());
        $this->_checkoutSession->setData('last_success_quote_id', $order->getQuoteId());
        $this->_checkoutSession->setData('last_quote_id', $order->getQuoteId());
        $this->_checkoutSession->setData('last_real_order_id', $orderId);
        $_SESSION['customer_base']['customer_id']           = $order->getCustomerId();
        $_SESSION['default']['visitor_data']['customer_id'] = $order->getCustomerId();
        $_SESSION['customer_base']['customer_id']           = $order->getCustomerId();
    }

    private function getOrder()
    {
        if (!$this->_order->getId()) {
            $this->setlastOrderDetails();

            return $this->_order;
        } else {
            return $this->_order;
        }
    }

    private function redirectIfOrderNotFound()
    {
        if (!$this->_order->getId()) {
            // Redirect to Cart if Order not found
            echo $this->redirectToSuccessPageString;
            exit;
        }
    }
}
