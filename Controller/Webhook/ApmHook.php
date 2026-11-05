<?php

namespace Fortispay\Fortis\Controller\Webhook;

use Exception;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Service\TransactionVerifier;
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
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class ApmHook implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    /**
     * @var array<int, string>
     */
    public static array $apmResponseStatuses = [
        151 => 'PayPal/Venmo Sale',
        152 => 'PayPal/Venmo Order Started',
        161 => 'PayPal/Venmo Refunded',
    ];

    // Fortis APM reason codes: 4001 pending, 4002 declined, 4003 completed, 4004 partially refunded, 4005 refunded.
    // Refunds are not told apart by reason (Fortis reports a partial refund as 4005 too), see handleRefund().
    private const REASON_PENDING   = 4001;
    private const REASON_DECLINED  = 4002;
    private const REASON_COMPLETED = 4003;

    private const STATUS_SALE          = 151;
    private const STATUS_ORDER_STARTED = 152;
    private const STATUS_REFUNDED      = 161;

    /**
     * How long a sale webhook waits for the return from PayPal to record the Magento transaction before it gives up.
     *
     * Fortis sends a sale's CREATE webhook as soon as the sale exists, and its UPDATE a few seconds later (setting the
     * description is an update), while the customer's return from PayPal only records the transaction after it has
     * read the sale back, patched its description and sent the order and invoice emails. On a fast link both webhooks
     * arrive first. The hook cannot complete the order itself: the return is invoicing it at that moment, and a second
     * invoice would fail the customer's return. So it waits for the return, which is what the webhook always assumed.
     */
    public const RETURN_WAIT_ATTEMPTS     = 20;
    public const RETURN_WAIT_MICROSECONDS = 500000;

    private const ACTION_PENDING           = 'pending';
    private const ACTION_DECLINED          = 'declined';
    private const ACTION_COMPLETED         = 'completed';
    private const ACTION_UNHANDLED         = 'unhandled';

    /**
     * @var TransactionRepositoryInterface
     */
    private TransactionRepositoryInterface $transactionRepository;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var RawFactory
     */
    private RawFactory $resultFactory;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var OrderSender
     */
    private OrderSender $orderSender;

    /**
     * @var InvoiceService
     */
    private InvoiceService $invoiceService;

    /**
     * @var Transaction
     */
    private Transaction $dbTransaction;

    /**
     * @var InvoiceSender
     */
    private InvoiceSender $invoiceSender;

    /**
     * @var CreditmemoFactory
     */
    private CreditmemoFactory $creditMemoFactory;

    /**
     * @var CreditmemoService
     */
    private CreditmemoService $creditMemoService;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @var TransactionVerifier
     */
    private TransactionVerifier $transactionVerifier;

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
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param TransactionVerifier $transactionVerifier
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
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        TransactionVerifier $transactionVerifier
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
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->transactionVerifier   = $transactionVerifier;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Process APM webhook updates.
     *
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function execute()
    {
        $pre      = __METHOD__ . " : ";
        $response = $this->resultFactory->create();

        $data = json_decode($this->request->getContent(), true);
        if (!is_array($data)) {
            $data = $this->request->getParams();
        }

        if (!in_array($data['type'] ?? '', ['UPDATE', 'CREATE'], true)) {
            $this->logger->error('Webhook type is not UPDATE or CREATE');
            $response->setHttpResponseCode(422);
            $response->setContents('Webhook type is not UPDATE or CREATE');

            return $response;
        }

        try {
            $transactionData = $data['data'] ?? null;
            if (is_string($transactionData)) {
                $transactionData = json_decode($transactionData, true);
            }

            if (!is_array($transactionData)) {
                $this->logger->error('APM Hook: webhook data is not a JSON object');
                $response->setHttpResponseCode(422);
                $response->setContents('Webhook data is not a JSON object');

                return $response;
            }

            // Never log or store the raw body: it carries payment method and billing details (PCI)
            $webhookSummary = [
                'type'           => $data['type'],
                'id'             => (string)($transactionData['id'] ?? ''),
                'status_code'    => (int)($transactionData['status_code'] ?? $transactionData['status_id'] ?? 0),
                'reason_code_id' => (int)($transactionData['reason_code_id'] ?? 0),
            ];
            $this->logger->info('APM Hook reached', $webhookSummary);

            $paymentMethod = $transactionData['payment_method'] ?? '';
            if ($paymentMethod !== 'apm') {
                $this->logger->error('Webhook payment_method is not apm');
                $response->setHttpResponseCode(422);
                $response->setContents('Webhook payment_method is not apm');

                return $response;
            }

            if ($webhookSummary['status_code'] === self::STATUS_REFUNDED) {
                return $this->handleRefund($response, $webhookSummary);
            }

            $transactionStatus = $webhookSummary['status_code'];
            $transactionId     = $webhookSummary['id'];
            $reasonCodeId      = $webhookSummary['reason_code_id'];

            // Find the Magento transaction by Fortis transaction ID, waiting for the return from PayPal to record it
            $transaction = $this->findSaleTransaction($transactionId);

            if ($transaction === null) {
                $this->logger->error('APM Hook: transaction not found for ID: ' . $transactionId);
                $response->setHttpResponseCode(404);
                $response->setContents('Transaction not found');

                return $response;
            }

            $orderId     = $transaction->getOrderId();
            $order       = $this->orderRepository->get($orderId);

            $orderTotal         = (int)bcmul($order->getGrandTotal(), '100', 0);
            $verificationResult = $this->transactionVerifier->verifyTransactionById(
                $transactionId,
                $order->getIncrementId(),
                $orderTotal
            );

            if (!$verificationResult['verified']) {
                $this->logger->critical('SECURITY: APM transaction verification failed', [
                    'transactionId' => $transactionId,
                    'order'         => $order->getIncrementId(),
                    'errors'        => $verificationResult['errors'],
                ]);
                $response->setHttpResponseCode(403);
                $response->setContents('Transaction verification failed');

                return $response;
            }

            $additionalInformation = $transaction->getAdditionalInformation();
            $webhookUpdateInfo     = $additionalInformation['webhook_update_info'] ?? [];
            $webhookUpdateInfo[]   = json_encode($webhookSummary);
            $transaction->setAdditionalInformation('webhook_update_info', $webhookUpdateInfo);

            switch ($this->resolveAction($transactionStatus, $reasonCodeId)) {
                case self::ACTION_COMPLETED:
                    // PayPal/Venmo Sale — payment completed
                    $transaction->setTxnType(TransactionInterface::TYPE_CAPTURE);

                    $message = 'PayPal payment completed (status ' . $transactionStatus
                        . ', reason ' . $reasonCodeId . ')';

                    if ($order->getState() === Order::STATE_HOLDED) {
                        // An earlier pending notice put the order on hold; it is paid now, so it can be invoiced
                        $order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
                    }

                    if (!$order->canInvoice()) {
                        // The return from PayPal normally invoices the order seconds before this webhook arrives:
                        // do not invoice, email or change the order state a second time
                        $order->addCommentToStatusHistory($message . '; the order was already invoiced');
                        break;
                    }

                    if ($this->config->orderSuccessfulEmail()) {
                        $this->orderSender->send($order);
                        $order->addCommentToStatusHistory(
                            __('Notified customer about order')
                        )->setIsCustomerNotified(true);
                    }

                    $invoice = $this->invoiceService->prepareInvoice($order);
                    $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                    $invoice->register();
                    $fortisTransaction = $this->dbTransaction
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder());
                    $fortisTransaction->save();

                    if ($this->config->emailInvoice()) {
                        $this->invoiceSender->send($invoice);
                        $order->addCommentToStatusHistory('Invoice emailed to customer');
                        $order->setIsCustomerNotified(true);
                    }

                    $invoice->setTransactionId($transaction->getTransactionId());

                    $order->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
                    $order->addCommentToStatusHistory($message);
                    break;

                case self::ACTION_PENDING:
                    // Order started, or a sale that is not completed: hold the order, never cancel it
                    $message = $transactionStatus === self::STATUS_ORDER_STARTED
                        ? 'PayPal order started — awaiting PayPal confirmation (status ' . $transactionStatus . ')'
                        : 'PayPal/Venmo payment pending (status ' . $transactionStatus
                            . ', reason ' . $reasonCodeId . ')';
                    $order->setState(Order::STATE_HOLDED)->setStatus(Order::STATE_HOLDED);
                    $order->addCommentToStatusHistory($message);
                    break;

                case self::ACTION_DECLINED:
                    // PayPal/Venmo declined — only an unpaid order may be cancelled
                    $message = 'PayPal/Venmo payment declined (status ' . $transactionStatus
                        . ', reason ' . $reasonCodeId . ')';
                    if (!count($order->getInvoiceCollection())) {
                        $order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
                    }
                    $order->addCommentToStatusHistory($message);
                    break;

                default:
                    $this->logger->error('APM Hook: unhandled status code ' . $transactionStatus);
                    break;
            }

            $this->transactionRepository->save($transaction);
            $this->orderRepository->save($order);

            $response->setHttpResponseCode(200);
            $response->setContents('OK');

            return $response;
        } catch (LocalizedException|Exception $e) {
            $this->logger->error($pre . $e->getMessage());
            $response->setHttpResponseCode(422);
            $response->setContents($e->getMessage());

            return $response;
        }
    }

    /**
     * Find the Magento transaction of a sale, waiting a little for the return from PayPal to record it.
     *
     * @param string $transactionId The Fortis transaction id
     * @return \Magento\Sales\Api\Data\TransactionInterface|null
     */
    private function findSaleTransaction(string $transactionId): ?TransactionInterface
    {
        for ($attempt = 0; $attempt <= self::RETURN_WAIT_ATTEMPTS; $attempt++) {
            $items = $this->transactionRepository->getList(
                $this->searchCriteriaBuilder->addFilter('txn_id', $transactionId, 'eq')->create()
            )->getItems();
            if (!empty($items)) {
                if ($attempt > 0) {
                    $this->logger->info('APM Hook: the sale was recorded after waiting', [
                        'id'      => $transactionId,
                        'retries' => $attempt,
                    ]);
                }

                return array_shift($items);
            }

            if ($attempt === 0) {
                $this->logger->info('APM Hook: the sale is not recorded yet, waiting for the return from PayPal', [
                    'id' => $transactionId,
                ]);
            }
            if ($attempt < self::RETURN_WAIT_ATTEMPTS) {
                $this->pause(self::RETURN_WAIT_MICROSECONDS);
            }
        }

        return null;
    }

    /**
     * Sleep between looks for the transaction (a method of its own so a test does not have to wait).
     *
     * @param int $microseconds
     * @return void
     */
    protected function pause(int $microseconds): void
    {
        usleep($microseconds);
    }

    /**
     * Decide what a sale or order-started webhook means.
     *
     * The status code gives the direction and the reason code the outcome. Only a completed sale (4003) is paid; a
     * declined one (4002) cancels an unpaid order; anything else, pending (4001) or an unknown reason, is never assumed
     * to be paid and only holds the order. Refunds (status 161) are handled separately.
     *
     * @param int $status
     * @param int $reasonCodeId
     * @return string
     */
    private function resolveAction(int $status, int $reasonCodeId): string
    {
        if (!in_array($status, [self::STATUS_SALE, self::STATUS_ORDER_STARTED], true)) {
            return self::ACTION_UNHANDLED;
        }

        if ($reasonCodeId === self::REASON_DECLINED) {
            return self::ACTION_DECLINED;
        }

        return $status === self::STATUS_SALE && $reasonCodeId === self::REASON_COMPLETED
            ? self::ACTION_COMPLETED
            : self::ACTION_PENDING;
    }

    /**
     * Apply a PayPal/Venmo refund event to the order it belongs to.
     *
     * Fortis records every refund as a new transaction, so the webhook id is the refund's own id and its reason
     * code is 4005 for partial and full refunds alike. The refund is read back from Fortis, matched to its order by
     * the description (the order number), added to a per-refund tally on the sale, and the order is cancelled and
     * credit-memoed only once the tally reaches the order total.
     *
     * @param \Magento\Framework\Controller\Result\Raw $response
     * @param array $webhookSummary
     * @return \Magento\Framework\Controller\Result\Raw
     * @throws LocalizedException
     */
    private function handleRefund($response, array $webhookSummary)
    {
        $refundId     = $webhookSummary['id'];
        $reasonCodeId = $webhookSummary['reason_code_id'];

        // The webhook body is not authenticated, so only trust what Fortis returns for this id
        $refund      = $refundId !== '' ? $this->transactionVerifier->fetchTransactionData($refundId) : null;
        $refundCents = (int)($refund['transaction_amount'] ?? 0);
        $refundState = (int)($refund['status_code'] ?? $refund['status_id'] ?? 0);

        if ($refund === null || $refundState !== self::STATUS_REFUNDED || $refundCents <= 0) {
            $this->logger->critical('SECURITY: APM refund verification failed', ['transactionId' => $refundId]);
            $response->setHttpResponseCode(403);
            $response->setContents('Refund verification failed');

            return $response;
        }

        [$order, $transaction] = $this->findRefundedSale($refundId, (string)($refund['description'] ?? ''));
        if ($order === null || $transaction === null) {
            $this->logger->error('APM Hook: no order found for refund ID: ' . $refundId);
            $response->setHttpResponseCode(404);
            $response->setContents('Transaction not found');

            return $response;
        }

        $additionalInformation = $transaction->getAdditionalInformation() ?: [];
        $refunds               = $additionalInformation['apm_refunds'] ?? [];
        if (isset($refunds[$refundId])) {
            // Fortis retried a refund that has already been applied
            $response->setHttpResponseCode(200);
            $response->setContents('OK');

            return $response;
        }

        $refunds[$refundId]    = $refundCents;
        $webhookUpdateInfo     = $additionalInformation['webhook_update_info'] ?? [];
        $webhookUpdateInfo[]   = json_encode($webhookSummary);
        $transaction->setAdditionalInformation('apm_refunds', $refunds);
        $transaction->setAdditionalInformation('webhook_update_info', $webhookUpdateInfo);

        $saleCents     = (int)bcmul((string)$order->getGrandTotal(), '100', 0);
        $refundedCents = (int)array_sum($refunds);
        $detail        = '(status ' . self::STATUS_REFUNDED . ', reason ' . $reasonCodeId . ')';
        $tally         = $this->formatCents($refundedCents) . ' of ' . $this->formatCents($saleCents);

        if ($refundedCents >= $saleCents) {
            $transaction->setTxnType(TransactionInterface::TYPE_REFUND);

            // Credit the invoices while the order can still be credited: a cancelled order cannot be
            if ($order->canCreditmemo()) {
                foreach ($order->getInvoiceCollection() as $invoice) {
                    $creditMemo = $this->creditMemoFactory->createByInvoice($invoice);
                    $creditMemo->setInvoice($invoice);
                    // Offline: the money has already been returned by Fortis
                    $this->creditMemoService->refund($creditMemo, true);
                }
            }

            // A refund started from a Magento credit memo has already closed the order
            if ($order->getState() !== Order::STATE_CLOSED) {
                $order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
            }
            $order->addCommentToStatusHistory('PayPal/Venmo refunded in full ' . $detail . ': ' . $tally . ' refunded');
        } else {
            // A refund started from a Magento credit memo has already been credited, so give no advice then
            $creditedCents = (int)bcmul((string)($order->getTotalRefunded() ?? 0), '100', 0);
            $advice        = $creditedCents >= $refundedCents
                ? ''
                : ' The order stays open; create a credit memo if needed.';
            $order->addCommentToStatusHistory(
                'PayPal/Venmo partial refund of ' . $this->formatCents($refundCents) . ' ' . $detail . ': ' . $tally
                . ' refunded so far.' . $advice
            );
        }

        $this->transactionRepository->save($transaction);
        $this->orderRepository->save($order);

        $response->setHttpResponseCode(200);
        $response->setContents('OK');

        return $response;
    }

    /**
     * Find the order a refund belongs to, and the Magento transaction of its sale.
     *
     * @param string $refundId
     * @param string $orderNumber The refund's description, which holds the order number
     * @return array{0: ?Order, 1: ?\Magento\Sales\Api\Data\TransactionInterface}
     */
    private function findRefundedSale(string $refundId, string $orderNumber): array
    {
        // A refund recorded in place on the sale shares the sale's transaction id
        $items = $this->transactionRepository->getList(
            $this->searchCriteriaBuilder->addFilter('txn_id', $refundId, 'eq')->create()
        )->getItems();
        if (!empty($items)) {
            $transaction = array_shift($items);

            return [$this->orderRepository->get($transaction->getOrderId()), $transaction];
        }

        if ($orderNumber === '') {
            return [null, null];
        }

        $orders = $this->orderRepository->getList(
            $this->searchCriteriaBuilder->addFilter('increment_id', $orderNumber, 'eq')->create()
        )->getItems();
        $order  = array_shift($orders);
        if ($order === null || $order->getPayment()?->getMethod() !== Config::METHOD_CODE) {
            return [null, null];
        }

        $items = $this->transactionRepository->getList(
            $this->searchCriteriaBuilder->addFilter('order_id', $order->getId(), 'eq')->create()
        )->getItems();
        $saleTxnId = $order->getPayment()->getLastTransId();
        foreach ($items as $item) {
            if ($item->getTxnId() === $saleTxnId) {
                return [$order, $item];
            }
        }

        return [$order, $items ? array_shift($items) : null];
    }

    /**
     * Format an amount in cents as a decimal string.
     *
     * @param int $cents
     * @return string
     */
    private function formatCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
