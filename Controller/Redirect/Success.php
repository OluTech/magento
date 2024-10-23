<?php

namespace Fortispay\Fortis\Controller\Redirect;

use Exception;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\Fortis;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Service\FortisMethodService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Url;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\DB\Transaction as DBTransaction;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Session\Generic;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Psr\Log\LoggerInterface;
use stdClass;
use Fortispay\Fortis\Service\CheckoutProcessor;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Success implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    /**
     * @var CheckoutSession $checkoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var  Order $order
     */
    private Order $order;

    /**
     * @var PageFactory
     */
    private PageFactory $pageFactory;

    /**
     * @var  StoreManagerInterface $storeManager
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var Fortis $paymentMethod
     */
    private Fortis $paymentMethod;

    /**
     * @var OrderRepositoryInterface $orderRepository
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var Builder
     */
    private Builder $transactionBuilder;
    /**
     * @var DBTransaction
     */
    private DBTransaction $dbTransaction;
    /**
     * @var InvoiceService
     */
    private InvoiceService $invoiceService;
    /**
     * @var InvoiceSender
     */
    private InvoiceSender $invoiceSender;
    /**
     * @var OrderSender
     */
    private OrderSender $orderSender;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var ResultFactory
     */
    private ResultFactory $resultFactory;

    private ManagerInterface $messageManager;

    private JsonFactory $resultJsonFactory;
    /**
     * @var EventManager
     */
    private EventManager $eventManager;
    /**
     * @var CountryFactory
     */
    private CountryFactory $countryFactory;
    /**
     * @var CountryCollectionFactory
     */
    private CountryCollectionFactory $countryCollectionFactory;
    /**
     * @var FortisMethodService
     */
    private FortisMethodService $fortisMethodService;
    /**
     * @var InvoiceRepositoryInterface
     */
    private InvoiceRepositoryInterface $invoiceRepository;
    /**
     * @var FortisApi
     */
    private FortisApi $fortisApi;
    private CheckoutProcessor $checkoutProcessor;

    /**
     * @param PageFactory $pageFactory
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param Fortis $paymentMethod
     * @param OrderRepositoryInterface $orderRepository
     * @param StoreManagerInterface $storeManager
     * @param OrderSender $OrderSender
     * @param Builder $transactionBuilder
     * @param DBTransaction $dbTransaction
     * @param Order $order
     * @param RequestInterface $request
     * @param ResultFactory $resultFactory
     * @param ManagerInterface $messageManager
     * @param JsonFactory $resultJsonFactory
     * @param EventManager $eventManager
     * @param CountryFactory $countryFactory
     * @param CountryCollectionFactory $countryCollectionFactory
     * @param FortisMethodService $fortisMethodService
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param FortisApi $fortisApi
     * @param CheckoutProcessor $checkoutProcessor
     */
    public function __construct(
        PageFactory $pageFactory,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        Fortis $paymentMethod,
        OrderRepositoryInterface $orderRepository,
        StoreManagerInterface $storeManager,
        OrderSender $OrderSender,
        Builder $transactionBuilder,
        DBTransaction $dbTransaction,
        Order $order,
        RequestInterface $request,
        ResultFactory $resultFactory,
        ManagerInterface $messageManager,
        JsonFactory $resultJsonFactory,
        EventManager $eventManager,
        CountryFactory $countryFactory,
        CountryCollectionFactory $countryCollectionFactory,
        FortisMethodService $fortisMethodService,
        InvoiceRepositoryInterface $invoiceRepository,
        FortisApi $fortisApi,
        CheckoutProcessor $checkoutProcessor
    ) {
        $pre = __METHOD__ . " : ";

        $this->logger = $logger;

        $this->logger->debug($pre . 'bof');

        $this->dbTransaction            = $dbTransaction;
        $this->order                    = $order;
        $this->checkoutSession          = $checkoutSession;
        $this->pageFactory              = $pageFactory;
        $this->invoiceService           = $invoiceService;
        $this->invoiceSender            = $invoiceSender;
        $this->orderSender              = $OrderSender;
        $this->paymentMethod            = $paymentMethod;
        $this->orderRepository          = $orderRepository;
        $this->storeManager             = $storeManager;
        $this->transactionBuilder       = $transactionBuilder;
        $this->request                  = $request;
        $this->resultFactory            = $resultFactory;
        $this->messageManager           = $messageManager;
        $this->resultJsonFactory        = $resultJsonFactory;
        $this->eventManager             = $eventManager;
        $this->countryFactory           = $countryFactory;
        $this->countryCollectionFactory = $countryCollectionFactory;
        $this->fortisMethodService      = $fortisMethodService;
        $this->invoiceRepository        = $invoiceRepository;
        $this->fortisApi                = $fortisApi;
        $this->checkoutProcessor        = $checkoutProcessor;

        $this->logger->debug($pre . 'eof');
    }

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
        1630 => 'REJECTED_BATCH (First attempt at batch close will fail with a transaction in the batch for $6.30.
        The second batch close attempt will succeed.)',
        1631 => 'ACCOUNT_CLOSED'
    ];

    public static array $achResponseStatuses = [
        131 => 'Pending Origination',
        132 => 'Originating',
        133 => 'Originated',
        134 => 'Settled',
        201 => 'Voided',
        301 => 'Declined',
        331 => 'Charged Back',
    ];

    /**
     * Execute on fortis/redirect/success
     *
     * @return \Magento\Framework\Controller\ResultInterface
     * @throws NoSuchEntityException
     */
    public function execute()
    {
        $json      = $this->request->getContent();
        $GET       = $this->request->getParams();
        $data      = json_decode($json);
        $tokenised = false;
        $orderId   = (int)$GET['gid'];
        if (!$data) {
            $tokenised = true;
            $data      = new stdClass();
            $data->id  = $GET['tid'];
        }

        $pre = __METHOD__ . " : ";
        $this->logger->debug($pre . 'bof');
        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order->getId()) {
            $order = $this->setlastOrderDetails();
        }
        $this->order = $order;

        $baseurl  = $this->storeManager->getStore()->getBaseUrl();
        $redirect = $this->resultFactory->create(
            ResultFactory::TYPE_REDIRECT
        );

        $redirectToSuccessPageString = $baseurl . 'checkout/onepage/success';
        $redirectToCartPageString    = $baseurl . 'checkout/cart';

        if ((int)$order->getId() !== $orderId) {
            $redirect->setUrl($redirectToSuccessPageString);

            return $redirect;
        }

        $orderHistories               = $order->getAllStatusHistory();
        $product_transaction_id_order = '';
        foreach ($orderHistories as $history) {
            if ($comment = $history->getComment()) {
                if (str_starts_with($comment, 'product_transaction_id')) {
                    $product_transaction_id_order = explode(':', $comment)[1];
                }
                break;
            }
        }
        $this->pageFactory->create();

        // Get the transaction
        try {
            $api               = $this->fortisApi;
            $user_id           = $this->checkoutProcessor->getConfigData('user_id');
            $user_api_key      = $this->checkoutProcessor->getConfigData('user_api_key');
            $fortisTransaction = $api->getTransaction($data->id, $user_id, $user_api_key)->data;

            if ($tokenised) {
                $data = $fortisTransaction;
            }

            $status = $fortisTransaction->status_code;
            if ($fortisTransaction->payment_method === 'ach') {
                // Handle response from ACH
                // Pending Origination
                if ($status == 131) {
                    $message = "ACH Transaction: " . self::$achResponseStatuses[$status];
                    $this->messageManager->addNoticeMessage($message);
                    $this->order->addStatusToHistory(__($message));
                    $status = Order::STATE_PENDING_PAYMENT;
                    // Save Transaction Response
                    $this->createTransaction($data);
                    $order->setState($status)->setStatus($status);
                    $this->orderRepository->save($order);

                    // Check for stored card and save if necessary
                    $model = $this->paymentMethod;
                    $this->fortisMethodService->saveVaultData($order, $data);

                    if (!$tokenised) {
                        $resultJson = $this->resultJsonFactory->create();

                        return $resultJson->setData([
                                                        'redirectTo' => $redirectToSuccessPageString,
                                                    ]);
                    } else {
                        $redirect->setUrl($redirectToSuccessPageString);
                    }
                }
            } else {
                // Handle response from CC transaction
                if (!$tokenised && ($fortisTransaction->product_transaction_id !== $product_transaction_id_order)) {
                    throw new RuntimeException(
                        __('Product transaction ids do not match')
                    );
                }
                $accountType = $fortisTransaction->account_type;
                switch ($status) {
                    case 101:  // Success
                    case 102:  // Success
                        // Check for stored card and save if necessary
                        $this->fortisMethodService->saveVaultData($order, $data);

                        $status = Order::STATE_PROCESSING;
                        if ($this->checkoutProcessor->getConfigData('Successful_Order_status') != "") {
                            $status = $this->checkoutProcessor->getConfigData('Successful_Order_status');
                        }

                        $model                  = $this->paymentMethod;
                        $order_successful_email = $model->getConfigData('order_email');

                        if ($order_successful_email != '0') {
                            $this->orderSender->send($order);
                            $order->addCommentToStatusHistory(
                                __('Notified customer about order #%1.', $order->getId())
                            )->setIsCustomerNotified(true);
                            $this->orderRepository->save($order);
                        }

                        // Capture invoice when payment is successful
                        $invoice = $this->invoiceService->prepareInvoice($order);
                        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                        $invoice->register();

                        // Save the invoice to the order
                        $fortisTransactionDB = $this->dbTransaction
                            ->addObject($invoice)
                            ->addObject($invoice->getOrder());

                        $fortisTransactionDB->save();

                        // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                        $send_invoice_email = $model->getConfigData('invoice_email');
                        if ($send_invoice_email != '0') {
                            $this->invoiceSender->send($invoice);
                            $order->addCommentToStatusHistory(
                                __('Notified customer about invoice #%1.', $invoice->getId())
                            )->setIsCustomerNotified(true);
                            $this->orderRepository->save($order);
                        }

                        // Save Transaction Response
                        $transactionId = $this->createTransaction($data);

                        $invoice->setTransactionId($transactionId);
                        $this->invoiceRepository->save($invoice);

                        $order->setState($status)->setStatus($status);
                        $this->orderRepository->save($order);

                        // Dispatch event to create third level data
                        try {
                            $this->eventManager->dispatch(
                                'fortispay_fortis_create_third_level_data_after_success',
                                [
                                    'order'                    => $order,
                                    'storeManager'             => $this->storeManager,
                                    'type'                     => $accountType,
                                    'countryFactory'           => $this->countryFactory,
                                    'countryCollectionFactory' => $this->countryCollectionFactory,
                                    'transactionId'            => $fortisTransaction->id,
                                ]
                            );
                        } catch (\Exception $exception) {
                            $this->logger->error('Could not create 3rd level data: ' . $exception->getMessage());
                        }

                        // Invoice capture code completed
                        if (!$tokenised) {
                            $resultJson = $this->resultJsonFactory->create();

                            return $resultJson->setData([
                                                            'redirectTo' => $redirectToSuccessPageString,
                                                        ]);
                        } else {
                            $redirect->setUrl($redirectToSuccessPageString);
                        }
                        break;
                    default:
                        if (isset($this->responseCodes[$status])) {
                            $message = "Not Authorised: " . $this->responseCodes[$status];
                        } else {
                            $message = 'Not Authorised: Reason Unknown';
                        }
                        $this->messageManager->addNoticeMessage($message);
                        $this->order->addStatusToHistory(__("Failed: $message "));
                        $this->order->cancel();
                        $this->orderRepository->save($this->order);
                        $this->checkoutSession->restoreQuote();
                        $this->createTransaction($data);
                        if (!$tokenised) {
                            $resultJson = $this->resultJsonFactory->create();

                            return $resultJson->setData([
                                                            'redirectTo' => $redirectToCartPageString,
                                                        ]);
                        } else {
                            $redirect->setUrl($redirectToCartPageString);
                        }
                }
            }
        } catch (Exception $e) {
            // Save Transaction Response
            $this->createTransaction($data);
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Fortis Checkout.'));

            $redirect->setUrl($redirectToCartPageString);
        }

        return $redirect;
    }

    /**
     * Create Transaction
     *
     * @param stdClass $paymentData
     *
     * @return int|void
     */
    public function createTransaction(StdClass $paymentData)
    {
        try {
            // Get payment object from order object
            $payment = $this->order->getPayment();
            $payment->setLastTransId($paymentData->id)
                    ->setTransactionId($paymentData->id)
                    ->setAdditionalInformation(
                        [Transaction::RAW_DETAILS => json_encode($paymentData)]
                    );
            $formattedPrice = $this->order->getBaseCurrency()->formatTxt(
                $this->order->getGrandTotal()
            );

            $message = __('The authorized amount is %1.', $formattedPrice);
            // Get the object of builder class
            $trans       = $this->transactionBuilder;
            $transaction = $trans->setPayment($payment)
                                 ->setOrder($this->order)
                                 ->setTransactionId($paymentData->id)
                                 ->setAdditionalInformation(
                                     [Transaction::RAW_DETAILS => json_encode($paymentData)]
                                 )
                                 ->setFailSafe(true)
                // Build method creates the transaction and returns the object
                                 ->build(
                                     $paymentData->payment_method === 'ach'
                                     ? Transaction::TYPE_ORDER
                                     : Transaction::TYPE_CAPTURE
                                 );

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId(null);
            $this->orderRepository->save($this->order);

            return $transaction->getTransactionId();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * Set Last Order Details
     *
     * @return OrderInterface
     */
    public function setlastOrderDetails()
    {
        $orderId = $this->request->getParam('gid');
        $order   = $this->orderRepository->get($orderId);
        $this->checkoutSession->setData('last_order_id', $order->getId());
        $this->checkoutSession->setData('last_success_quote_id', $order->getQuoteId());
        $this->checkoutSession->setData('last_quote_id', $order->getQuoteId());
        $this->checkoutSession->setData('last_real_order_id', $orderId);

        return $order;
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
