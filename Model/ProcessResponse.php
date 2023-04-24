<?php

namespace Fortispay\Fortis\Model;

use Exception;
use Fortispay\Fortis\Controller\AbstractFortism220;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;

class ProcessResponse
{
    private string $redirectToSuccessPageString;

    public function processResponse($data, $order)
    {
        $pre = __METHOD__ . " : ";
//        $this->_logger->debug($pre . 'bof');

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
            $api          = new FortisApi($this->getConfigData('fortis_environment'));
            $user_id      = $this->getConfigData('user_id');
            $user_api_key = $this->getConfigData('user_api_key');
            $transaction  = $api->getTransaction($data->id, $user_id, $user_api_key)->data;

            if ($transaction->product_transaction_id !== $product_transaction_id_order) {
                throw new \Exception('Product transaction ids do not match');
            }
            $status = $transaction->reason_code_id;
            switch ($status) {
                case 1000:  // Success
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

                    // Capture invoice when payment is successfull
                    $invoice = $this->_invoiceService->prepareInvoice($order);
                    $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                    $invoice->register();

                    // Save the invoice to the order
                    $transaction = $this->dbTransaction
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder());

                    $transaction->save();

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
                    echo json_encode(
                        ['redirectTo' => $this->redirectToSuccessPageString]
                    );
                    exit;
            }

            $order = $this->orderRepository->get($order->getId());
            if (isset($data['TRANSACTION_STATUS'])) {
                $status = 1;
                switch ($status) {
                    case 1:

                    case 2:
                        $this->messageManager->addNotice('Transaction has been declined.');
                        $this->_order->addStatusHistoryComment(
                            __(
                                'Redirect Response, Transaction has been declined, Pay_Request_Id: ' . $data['PAY_REQUEST_ID']
                            )
                        )->setIsCustomerNotified(false);
                        $this->_order->cancel()->save();
                        $this->_checkoutSession->restoreQuote();
                        // Save Transaction Response
                        $this->createTransaction($data);
                        echo $this->redirectToCartPageString;
                        exit;
                    case 0:
                    case 4:
                        $this->messageManager->addNotice('Transaction has been cancelled');
                        $this->_order->addStatusHistoryComment(
                            __(
                                'Redirect Response, Transaction has been cancelled, Pay_Request_Id: ' . $data['PAY_REQUEST_ID']
                            )
                        )->setIsCustomerNotified(false);
                        $this->_order->cancel()->save();
                        $this->_checkoutSession->restoreQuote();
                        // Save Transaction Response
                        $this->createTransaction($data);
                        echo $this->redirectToCartPageString;
                        exit;
                    default:
                        // Save Transaction Response
                        $this->createTransaction($data);
                        break;
                }
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

    private function redirectIfOrderNotFound()
    {
        if (!$this->_order->getId()) {
            // Redirect to Cart if Order not found
            echo $this->redirectToSuccessPageString;
            exit;
        }
    }
}
