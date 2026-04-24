<?php

namespace Fortispay\Fortis\Controller\Redirect;

use Exception;
use Fortispay\Fortis\Model\Fortis;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Service\TransactionVerifier;
use Fortispay\Fortis\Service\CheckoutProcessor;
use Fortispay\Fortis\Service\FortisMethodService;
use Magento\Quote\Model\QuoteRepository;
use Fortispay\Fortis\Service\MagentoOrderService;
use Magento\Checkout\Model\Session as CheckoutSession;
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
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\TransactionInterface;
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
use Psr\Log\LoggerInterface;
use stdClass;

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
    private MagentoOrderService $magentoOrderService;
    private TransactionVerifier $transactionVerifier;
    private QuoteRepository $quoteRepository;

    /**
     * @param PageFactory $pageFactory
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param Fortis $paymentMethod
     * @param OrderRepositoryInterface $orderRepository
     * @param StoreManagerInterface $storeManager
     * @param OrderSender $orderSender
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
     * @param MagentoOrderService $magentoOrderService
     * @param TransactionVerifier $transactionVerifier
     * @param QuoteRepository $quoteRepository
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
        OrderSender $orderSender,
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
        CheckoutProcessor $checkoutProcessor,
        MagentoOrderService $magentoOrderService,
        TransactionVerifier $transactionVerifier,
        QuoteRepository $quoteRepository
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
        $this->orderSender              = $orderSender;
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
        $this->magentoOrderService      = $magentoOrderService;
        $this->transactionVerifier      = $transactionVerifier;
        $this->quoteRepository          = $quoteRepository;

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
        $json          = $this->request->getContent();
        $requestParams = $this->request->getParams();
        $data          = json_decode($json);
        $dataArray     = json_decode($json, true);

        $baseurl  = $this->storeManager->getStore()->getBaseUrl();
        $redirect = $this->resultFactory->create(
            ResultFactory::TYPE_REDIRECT
        );

        $redirectToSuccessPageString = $baseurl . 'checkout/onepage/success';
        $redirectToCartPageString    = $baseurl . 'checkout/cart';

        $isTicketTransaction = isset($dataArray) && ($dataArray['@action'] === 'ticket');

        $tokenised = false;
        $orderId   = isset($requestParams['gid']) ? (int)$requestParams['gid'] : null;
        if (!$data) {
            $tokenised = true;
            $data      = new stdClass();
            $data->id  = $requestParams['tid'] ?? null;
        }

        $pre = __METHOD__ . " : ";
        $this->logger->debug($pre . 'bof');
        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order->getId() && $this->request->getParam('gid')) {
            $order = $this->setlastOrderDetails();
        }

        $orderData      = $order->getPayment()->getData();
        $additionalData = $orderData['additional_information'];

        $this->order = $order;

        if ((!$isTicketTransaction && !$tokenised && ((int)$order->getId() !== $orderId)) || !$data) {
            $redirect->setUrl($redirectToCartPageString);

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
            $api          = $this->fortisApi;
            $user_id      = $this->paymentMethod->getSpecialConfigData('user_id');
            $user_api_key = $this->paymentMethod->getSpecialConfigData('user_api_key');

            if ($isTicketTransaction) {
                $transactionId = $dataArray['transactionId'] ?? null;
                if ($transactionId) {
                    $fortisTransactionObj = $this->fortisApi->getTransaction($transactionId, $user_id, $user_api_key);
                    $data                 = $fortisTransactionObj->data ?? $fortisTransactionObj;
                    $fortisTransaction    = $data;
                    $this->fortisApi->patchTransactionDescription($transactionId, $order->getIncrementId());
                } else {
                    $data              = (object)[];
                    $fortisTransaction = $data;
                }
            } else {
                $fortisTransaction = $api->getTransaction($data->id, $user_id, $user_api_key)->data;
            }

            $orderTotal    = (int)bcmul($order->getGrandTotal(), '100', 0);
            $surchargeInfo = null;
            if (!empty($additionalData['fortis-surcharge-data'])) {
                $surchargeDecoded = json_decode($additionalData['fortis-surcharge-data'], true);
                if (isset($surchargeDecoded['surcharge_amount']) && $surchargeDecoded['surcharge_amount'] > 0) {
                    $surchargeInfo = ['surchargeAmount' => (int)$surchargeDecoded['surcharge_amount']];
                }
            } elseif (isset($data->surcharge_amount) && $data->surcharge_amount > 0) {
                $surchargeInfo = ['surchargeAmount' => (int)$data->surcharge_amount];
            } elseif (isset($data->surcharge->surcharge_amount) && $data->surcharge->surcharge_amount > 0) {
                $surchargeInfo = ['surchargeAmount' => (int)$data->surcharge->surcharge_amount];
            }

            $verificationResult = $this->transactionVerifier->verifyTransactionById(
                $data->id ?? $fortisTransaction->id,
                $order->getIncrementId(),
                $orderTotal,
                $surchargeInfo
            );

            if (!$verificationResult['verified']) {
                $this->logger->critical('SECURITY: Transaction verification failed', [
                    'transactionId' => $data->id ?? $fortisTransaction->id,
                    'order'         => $order->getIncrementId(),
                    'errors'        => $verificationResult['errors']
                ]);
                throw new RuntimeException(new \Magento\Framework\Phrase('Transaction verification failed'));
            }

            if ($tokenised) {
                $data = $fortisTransaction;
            }

            $status = $isTicketTransaction ? $fortisTransaction->reason_code_id : $fortisTransaction->status_code;
            if ($fortisTransaction->payment_method === 'ach') {
                // Handle response from ACH
                // Pending Origination
                if ($status == 131) {
                    $message = "ACH Transaction: " . self::$achResponseStatuses[$status];
                    $this->messageManager->addNoticeMessage($message);
                    $this->order->addStatusToHistory(__($message));
                    $status = Order::STATE_HOLDED;
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
                if (!$tokenised && !$isTicketTransaction &&
                    ($fortisTransaction->product_transaction_id !== $product_transaction_id_order && !empty(
                        $this->paymentMethod->getSpecialConfigData(
                            'product_transaction_id'
                        )
                    ))) {
                    throw new RuntimeException(
                        __('Product transaction ids do not match')
                    );
                }
                $accountType = $fortisTransaction->account_type;
                switch ($status) {
                    case 1000:  // Success for ticket transaction
                    case 101:   // Success for non-ticket transaction
                    case 102:
                        // Check for stored card and save if necessary
                        $this->fortisMethodService->saveVaultData($order, $data);

                        $status = Order::STATE_PROCESSING;
                        if ($this->paymentMethod->getSpecialConfigData('Successful_Order_status') != "") {
                            $status = $this->paymentMethod->getSpecialConfigData('Successful_Order_status');
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

                        $surchargeAmount = 0.00;
                        if (isset($surchargeData)) {
                            $surchargeAmount = $surchargeData['surcharge_amount'];
                        } elseif (!empty($additionalData['fortis-surcharge-data'])) {
                            $surchargeData   = json_decode($additionalData['fortis-surcharge-data'], true);
                            $surchargeAmount = $surchargeData['surcharge_amount'];
                        } elseif (isset($data->surcharge_amount)) {
                            $surchargeAmount = $data->surcharge_amount;
                        } elseif (isset($data->surcharge->surcharge_amount)) {
                            $surchargeAmount = $data->surcharge->surcharge_amount;
                        }

                        try {
                            $this->magentoOrderService->applySurcharge($order, $surchargeAmount, $invoice);
                        } catch (\Exception $e) {
                            $this->logger->error('Error applying surcharge: ' . $e->getMessage());
                        }

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
                        $this->reactivateQuoteIfNeeded($this->order);
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
            if (isset($data) && is_object($data)) {
                $this->createTransaction($data);
            }
            $this->logger->error($pre . $e->getMessage());

            $this->checkoutSession->restoreQuote();
            if (isset($order) && $order->getId()) {
                $this->reactivateQuoteIfNeeded($order);
            }

            if ($tokenised || $isTicketTransaction) {
                $resultJson = $this->resultJsonFactory->create();
                return $resultJson->setData([
                                                'error'   => true,
                                                'message' => $e->getMessage() ?: __(
                                                    'Payment verification failed. Please try again.'
                                                )
                                            ])->setHttpResponseCode(403);
            }

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
                        ? TransactionInterface::TYPE_ORDER
                        : TransactionInterface::TYPE_CAPTURE
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
     * Reactivate the quote directly if restoreQuote() did not work (e.g. guest checkout with lost session).
     */
    private function reactivateQuoteIfNeeded(Order $order): void
    {
        try {
            $quoteId = $order->getQuoteId();
            if (!$quoteId) {
                return;
            }
            $quote = $this->quoteRepository->get($quoteId);
            if (!$quote->getIsActive()) {
                $quote->setIsActive(true);
                $this->quoteRepository->save($quote);
            }
            $this->checkoutSession->replaceQuote($quote);
        } catch (Exception $e) {
            $this->logger->error('Failed to reactivate quote: ' . $e->getMessage());
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
        $this->checkoutSession->setData('last_real_order_id', $order->getIncrementId());

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
