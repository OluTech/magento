<?php

namespace Fortispay\Fortis\Controller\Webhook;

use Exception;
use Fortispay\Fortis\Model\Config;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Service\InvoiceService;
use \Magento\Framework\DB\Transaction;
use \Magento\Sales\Model\Order\CreditmemoFactory;
use \Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Api\OrderRepositoryInterface;

class AchHook implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    /**
     * @var \Magento\Sales\Api\TransactionRepositoryInterface
     */
    private TransactionRepositoryInterface $transactionRepository;
    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private ResourceConnection $resourceConnection;
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private RequestInterface $request;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var \Magento\Framework\Controller\Result\RawFactory
     */
    private RawFactory $resultFactory;

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
     * @var \Fortispay\Fortis\Model\Config
     */
    private Config $config;
    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    private OrderSender $orderSender;
    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    private InvoiceService $invoiceService;
    /**
     * @var \Magento\Framework\DB\Transaction
     */
    private Transaction $dbTransaction;
    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    private InvoiceSender $invoiceSender;
    /**
     * @var \Magento\Sales\Model\Order\CreditmemoFactory
     */
    private CreditmemoFactory $creditMemoFactory;
    /**
     * @var \Magento\Sales\Model\Service\CreditmemoService
     */
    private CreditmemoService $creditMemoService;
    private OrderRepositoryInterface $orderRepository;

    /**
     * @param RequestInterface $request
     * @param ResourceConnection $resourceConnection
     * @param TransactionRepositoryInterface $transactionRepository
     * @param LoggerInterface $logger
     * @param RawFactory $resultFactory
     * @param Config $config
     * @param OrderSender $orderSender
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param Transaction $dbTransaction
     * @param CreditmemoService $creditMemoService
     * @param CreditmemoFactory $creditMemoFactory
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        RequestInterface $request,
        ResourceConnection $resourceConnection,
        TransactionRepositoryInterface $transactionRepository,
        LoggerInterface $logger,
        RawFactory $resultFactory,
        Config $config,
        OrderSender $orderSender,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        Transaction $dbTransaction,
        CreditmemoService $creditMemoService,
        CreditmemoFactory $creditMemoFactory,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->request               = $request;
        $this->resourceConnection    = $resourceConnection;
        $this->transactionRepository = $transactionRepository;
        $this->logger                = $logger;
        $this->resultFactory         = $resultFactory;
        $this->config                = $config;
        $this->orderSender           = $orderSender;
        $this->invoiceService        = $invoiceService;
        $this->dbTransaction         = $dbTransaction;
        $this->invoiceSender         = $invoiceSender;
        $this->creditMemoFactory     = $creditMemoFactory;
        $this->creditMemoService     = $creditMemoService;
        $this->orderRepository       = $orderRepository;
    }

    /**
     * Execute
     */
    public function execute()
    {
        $pre      = __METHOD__ . " : ";
        $response = $this->resultFactory->create();

        $data = json_decode($this->request->getContent(), true);
        if (!$data) {
            $data = $this->request->getParams();
        }

        $this->logger->info('ACH Hook has been reached');

        $this->logger->info('Data: ' . json_encode($data));
        if (($data['type'] ?? '') !== 'UPDATE') {
            $this->logger->error('Webhook type is not UPDATE');
            $response->setHttpResponseCode(422);
            $response->setContents('Webhook type is not UPDATE');

            return $response;
        }

        try {
            if (is_string($data['data'])) {
                $transactionData = json_decode($data['data'], true);
            } else {
                $transactionData = $data['data'];
            }
            if ($transactionData['payment_method'] !== 'ach') {
                $this->logger->error('Webhook type is not ACH');
                $response->setHttpResponseCode(422);
                $response->setContents('Webhook type is not ACH');

                return $response;
            }

            $transactionStatus = 0;
            if (isset($transactionData['status_code'])) {
                $transactionStatus = $transactionData['status_code'];
            } elseif (isset($transactionData['status_id'])) {
                $transactionStatus = $transactionData['status_id'];
            }
            $transactionId = $transactionData['id'] ?? '';

            // Query sales_payment_transaction to find transaction
            $connection = $this->resourceConnection->getConnection();
            $tableName  = $this->resourceConnection->getTableName('sales_payment_transaction');

            $query = "SELECT * FROM $tableName WHERE txn_id = :transaction_id";
            $binds = ['transaction_id' => $transactionId];

            $result = $connection->fetchRow($query, $binds);

            $transaction           = $this->transactionRepository->get($result['transaction_id']);
            $orderId               = $transaction->getOrderId();
            $order                 = $this->orderRepository->get($orderId);
            $additionalInformation = $transaction->getAdditionalInformation();
            if (empty($additionalInformation['webhook_update_info'])) {
                $webhookUpdateInformation = [];
            } else {
                $webhookUpdateInformation = $additionalInformation['webhook_update_info'];
            }
            switch ($transactionStatus) {
                case 131:
                case 132:
                case 133:
                    // Still payment pending, update transaction only
                    $webhookUpdateInformation[] = json_encode($data);
                    $transaction->setAdditionalInformation('webhook_update_info', $webhookUpdateInformation);
                    break;
                case 201:
                case 301:
                    // Payment failed
                    $webhookUpdateInformation[] = json_encode($data);
                    $transaction->setAdditionalInformation('webhook_update_info', $webhookUpdateInformation);
                    $error = "Payment failed. Status code: $transactionStatus ";
                    $error .= self::$achResponseStatuses[$transactionStatus];
                    $order->addStatusToHistory($error);
                    $orderState = Order::STATE_CANCELED;
                    $order->setState($orderState)->setStatus($orderState);
                    break;
                case 331:
                    // Charge back i.e refunded
                    $webhookUpdateInformation[] = json_encode($data);
                    $transaction->setAdditionalInformation('webhook_update_info', $webhookUpdateInformation);
                    $transaction->setTxnType(TransactionInterface::TYPE_REFUND);
                    $error = "Payment refunded. Status code: $transactionStatus ";
                    $error .= self::$achResponseStatuses[$transactionStatus];
                    $order->addStatusToHistory($error);
                    $orderState = Order::STATE_CANCELED;
                    $order->setState($orderState)->setStatus($orderState);

                    // Get invoices for order
                    $invoices = $order->getInvoiceCollection();
                    foreach ($invoices as $invoice) {
                        $creditMemo = $this->creditMemoFactory->createByInvoice($invoice);
                        $creditMemo->setInvoice($invoice);
                        $this->creditMemoService->refund($creditMemo);
                    }
                    break;
                case 134:
                    // Settled
                    $webhookUpdateInformation[] = json_encode($data);
                    $transaction->setAdditionalInformation('webhook_update_info', $webhookUpdateInformation);
                    $transaction->setTxnType(TransactionInterface::TYPE_CAPTURE);

                    // Send email if configured
                    if ($this->config->orderSuccessfulEmail()) {
                        $this->orderSender->send($order);
                        $order->addStatusToHistory(
                            __('Notified customer about order')
                        )->setIsCustomerNotified(true);
                    }

                    // Capture invoice for successful payment
                    $invoice = $this->invoiceService->prepareInvoice($order);
                    $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                    $invoice->register();
                    // and save it to the order
                    $fortisTransaction = $this->dbTransaction
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder());
                    $fortisTransaction->save();

                    // Email invoice if required
                    if ($this->config->emailInvoice()) {
                        $this->invoiceSender->send($invoice);
                        $order->addStatusToHistory('Invoice emailed to customer');
                        $order->setIsCustomerNotified(true);
                    }
                    $invoice->setTransactionId($transaction->getTransactionId());

                    $message = "Payment succeeded. Status code: $transactionStatus ";
                    $message .= self::$achResponseStatuses[$transactionStatus];
                    $order->addStatusToHistory($message);
                    $orderState = Order::STATE_PROCESSING;
                    $order->setState($orderState)->setStatus($orderState);
                    break;
                default:
                    $this->logger->error("Response code received was $transactionStatus");
                    break;
            }
            $this->orderRepository->save($order);

            $response->setHttpResponseCode(200);
            $response->setContents('OK');

            return $response;
        } catch (LocalizedException|Exception $e) {
            $this->logger->error($pre . $e->getMessage());
            $response->setHttpResponseCode(422);
            $response->setContents('Error ' . $e->getMessage());

            return $response;
        }
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
