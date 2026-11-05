<?php
/**
 * Unit tests for the APM (PayPal/Venmo) webhook controller
 * Covers the order history comments, the final order state and the persistence of the Magento transaction
 */

namespace Fortispay\Fortis\Test\Unit\Controller\Webhook;

use Fortispay\Fortis\Controller\Webhook\ApmHook;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Service\TransactionVerifier;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\DB\Transaction as DbTransaction;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\Data\TransactionSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// The partial Order, Transaction and Raw doubles act as stubs in tests that set no expectations on them
#[AllowMockObjectsWithoutExpectations]
class ApmHookTest extends TestCase
{
    private const FORTIS_TXN_ID = 'fortis-apm-txn-1';
    private const REFUND_TXN_ID = 'fortis-apm-refund-1';

    /**
     * @var Http|\PHPUnit\Framework\MockObject\MockObject
     */
    private $request;

    /**
     * @var TransactionRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $transactionRepository;

    /**
     * @var OrderRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orderRepository;

    /**
     * @var Config|\PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

    /**
     * @var InvoiceService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $invoiceService;

    /**
     * @var DbTransaction|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dbTransaction;

    /**
     * @var Raw|\PHPUnit\Framework\MockObject\MockObject
     */
    private $result;

    /**
     * @var Transaction|\PHPUnit\Framework\MockObject\MockObject
     */
    private $transaction;

    /**
     * @var Order|\PHPUnit\Framework\MockObject\MockObject
     */
    private $order;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var TransactionVerifier|\PHPUnit\Framework\MockObject\MockObject
     */
    private $verifier;

    /**
     * @var CreditmemoService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $creditMemoService;

    /**
     * @var CreditmemoFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $creditMemoFactory;

    /**
     * @var ApmHook
     */
    private ApmHook $controller;

    protected function setUp(): void
    {
        $this->request = $this->createStub(Http::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->invoiceService = $this->createStub(InvoiceService::class);
        $this->dbTransaction = $this->createStub(DbTransaction::class);
        $this->result = $this->getMockBuilder(Raw::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setHttpResponseCode', 'setContents'])
            ->getMock();
        $this->result->method('setHttpResponseCode')->willReturnSelf();
        $this->result->method('setContents')->willReturnSelf();

        $this->verifier = $this->createStub(TransactionVerifier::class);
        $this->verifier->method('verifyTransactionById')->willReturn(['verified' => true, 'errors' => []]);
        $this->creditMemoService = $this->createMock(CreditmemoService::class);
        $this->creditMemoFactory = $this->createStub(CreditmemoFactory::class);

        $this->transaction = $this->buildTransaction([]);
        $this->order = $this->buildOrder();

        $this->orderRepository->method('get')->willReturn($this->order);

        $searchResult = $this->createStub(TransactionSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$this->transaction]);
        $this->transactionRepository->method('getList')->willReturn($searchResult);

        $this->buildController();
    }

    /**
     * Build a Magento transaction double holding the given additional information
     *
     * @param array $additionalInformation
     * @return Transaction|\PHPUnit\Framework\MockObject\MockObject
     */
    private function buildTransaction(array $additionalInformation)
    {
        $transaction = $this->getMockBuilder(Transaction::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderId', 'getTransactionId', 'getTxnId', 'getAdditionalInformation',
                'setAdditionalInformation', 'setTxnType'])
            ->getMock();
        $transaction->method('getOrderId')->willReturn(42);
        $transaction->method('getTransactionId')->willReturn(7);
        $transaction->method('getTxnId')->willReturn(self::FORTIS_TXN_ID);
        $transaction->method('getAdditionalInformation')->willReturn($additionalInformation);

        return $transaction;
    }

    /**
     * Build an order double for a $100.00 Fortis order
     *
     * @param array $invoices
     * @param bool $canCreditmemo
     * @param string $method
     * @param bool|\Closure $canInvoice A fixed answer, or a callback that gets the order and answers from its state
     * @return Order|\PHPUnit\Framework\MockObject\MockObject
     */
    private function buildOrder(
        array $invoices = [],
        bool $canCreditmemo = true,
        string $method = 'fortis',
        bool|\Closure $canInvoice = true
    ) {
        $payment = $this->createStub(Order\Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getLastTransId')->willReturn(self::FORTIS_TXN_ID);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getGrandTotal', 'getIncrementId', 'getInvoiceCollection', 'addCommentToStatusHistory',
                'addStatusToHistory', 'getPayment', 'canCreditmemo', 'canInvoice'])
            ->getMock();
        $order->method('getGrandTotal')->willReturn(100.00); // $100.00, 10000 cents
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getInvoiceCollection')->willReturn($invoices);
        $order->method('getPayment')->willReturn($payment);
        $order->method('canInvoice')->willReturnCallback(
            fn() => $canInvoice instanceof \Closure ? $canInvoice($order) : $canInvoice
        );
        // Like Magento, an order that is already cancelled can no longer be credited
        $order->method('canCreditmemo')->willReturnCallback(
            fn() => $canCreditmemo && $order->getState() !== Order::STATE_CANCELED
        );
        $order->setId(42);
        $order->setState(Order::STATE_PENDING_PAYMENT);
        $order->setStatus(Order::STATE_PENDING_PAYMENT);

        return $order;
    }

    /**
     * Build the controller from the current doubles. It counts its pauses instead of sleeping.
     *
     * @param TransactionRepositoryInterface|null $repository Used instead of the default one
     */
    private function buildController(?TransactionRepositoryInterface $repository = null): void
    {
        $searchCriteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createStub(SearchCriteriaInterface::class));

        $resultFactory = $this->createStub(RawFactory::class);
        $resultFactory->method('create')->willReturn($this->result);

        $this->controller = new class (
            $this->request,
            $this->createStub(ResourceConnection::class),
            $repository ?? $this->transactionRepository,
            $this->logger,
            $resultFactory,
            $this->config,
            $this->createStub(OrderSender::class),
            $this->invoiceService,
            $this->createStub(InvoiceSender::class),
            $this->dbTransaction,
            $this->creditMemoService,
            $this->creditMemoFactory,
            $this->orderRepository,
            $searchCriteriaBuilder,
            $this->verifier
        ) extends ApmHook {
            /**
             * @var int
             */
            public int $pauses = 0;

            protected function pause(int $microseconds): void
            {
                $this->pauses++;
            }
        };
    }

    /**
     * Point the request at an APM webhook carrying the given Fortis status code
     *
     * @param int $statusCode
     * @param int $reasonCodeId
     */
    private function sendWebhook(int $statusCode, int $reasonCodeId = 0): void
    {
        $this->request->method('getContent')->willReturn(json_encode([
            'type' => 'UPDATE',
            'data' => [
                'id'             => self::FORTIS_TXN_ID,
                'payment_method' => 'apm',
                'status_code'    => $statusCode,
                'reason_code_id' => $reasonCodeId,
            ],
        ]));
    }

    /**
     * Stub the invoice flow used by a settled payment
     */
    private function stubInvoiceFlow(): void
    {
        $invoice = $this->getMockBuilder(Invoice::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register', 'getOrder'])
            ->getMock();
        $invoice->method('getOrder')->willReturn($this->order);
        $this->invoiceService->method('prepareInvoice')->willReturn($invoice);
        $this->dbTransaction->method('addObject')->willReturnSelf();
    }

    /**
     * A completed PayPal sale stores the message as the comment, keeps the processing state and saves the capture type
     */
    public function testSaleWebhookAddsCommentAndSavesCaptureTransaction(): void
    {
        $this->sendWebhook(151, 4003);
        $this->stubInvoiceFlow();

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal payment completed (status 151, reason 4003)');
        $this->order->expects($this->never())->method('addStatusToHistory');
        $this->transaction->expects($this->once())
            ->method('setTxnType')
            ->with(TransactionInterface::TYPE_CAPTURE);
        $this->transactionRepository->expects($this->once())->method('save')->with($this->transaction);
        $this->result->expects($this->once())->method('setHttpResponseCode')->with(200);

        $this->controller->execute();

        $this->assertSame(Order::STATE_PROCESSING, $this->order->getState());
        $this->assertSame(Order::STATE_PROCESSING, $this->order->getStatus());
    }

    /**
     * A sale webhook for an order that the return from PayPal already invoiced does not invoice it again
     */
    public function testSaleWebhookDoesNotInvoiceAnAlreadyInvoicedOrder(): void
    {
        $this->order = $this->buildOrder([], true, 'fortis', false);
        $this->order->setState(Order::STATE_COMPLETE);
        $this->order->setStatus(Order::STATE_COMPLETE);
        $this->orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $this->orderRepository->method('get')->willReturn($this->order);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->buildController();
        $this->sendWebhook(151, 4003);

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal payment completed (status 151, reason 4003); the order was already invoiced');
        $this->transaction->expects($this->once())->method('setTxnType')->with(TransactionInterface::TYPE_CAPTURE);
        $this->result->expects($this->once())->method('setHttpResponseCode')->with(200);

        $this->controller->execute();

        $this->assertSame(Order::STATE_COMPLETE, $this->order->getState());
    }

    /**
     * The customer and invoice emails are recorded as comments, not as statuses
     */
    public function testSaleWebhookRecordsEmailNotesAsComments(): void
    {
        $this->sendWebhook(151, 4003);
        $this->stubInvoiceFlow();
        $this->config->method('orderSuccessfulEmail')->willReturn(true);
        $this->config->method('emailInvoice')->willReturn(true);

        $comments = [];
        $this->order->method('addCommentToStatusHistory')->willReturnCallback(
            function ($comment) use (&$comments) {
                $comments[] = (string)$comment;

                return $this->createStub(History::class);
            }
        );
        $this->order->expects($this->never())->method('addStatusToHistory');

        $this->controller->execute();

        $this->assertSame(
            [
                'Notified customer about order',
                'Invoice emailed to customer',
                'PayPal payment completed (status 151, reason 4003)',
            ],
            $comments
        );
    }

    /**
     * An order started at PayPal is put on hold with the message as the comment
     */
    public function testOrderStartedWebhookAddsCommentAndHoldsOrder(): void
    {
        $this->sendWebhook(152);

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal order started — awaiting PayPal confirmation (status 152)');
        $this->order->expects($this->never())->method('addStatusToHistory');
        $this->transactionRepository->expects($this->once())->method('save')->with($this->transaction);

        $this->controller->execute();

        $this->assertSame(Order::STATE_HOLDED, $this->order->getState());
        $this->assertSame(Order::STATE_HOLDED, $this->order->getStatus());
    }

    /**
     * Arrange a refund event: Fortis returns the refund, the order is found by the refund's description
     *
     * @param int $refundCents
     * @param array $options priorRefunds, verified, orderFound, canCreditmemo, method, invoices, foundById
     */
    private function arrangeRefund(int $refundCents, array $options = []): void
    {
        $o = array_merge([
            'priorRefunds'  => [],
            'verified'      => true,
            'orderFound'    => true,
            'canCreditmemo' => true,
            'method'        => 'fortis',
            'invoices'      => [],
            'foundById'     => false,
        ], $options);

        $this->request->method('getContent')->willReturn(json_encode([
            'type' => 'CREATE',
            'data' => [
                'id'             => self::REFUND_TXN_ID,
                'payment_method' => 'apm',
                'status_code'    => 161,
                'reason_code_id' => 4005,
            ],
        ]));

        $this->verifier = $this->createStub(TransactionVerifier::class);
        $this->verifier->method('fetchTransactionData')->willReturn($o['verified'] ? [
            'id'                 => self::REFUND_TXN_ID,
            'status_code'        => 161,
            'transaction_amount' => $refundCents,
            'description'        => '000000042',
        ] : null);

        $this->order = $this->buildOrder($o['invoices'], $o['canCreditmemo'], $o['method']);
        $this->transaction = $this->buildTransaction($o['priorRefunds'] ? ['apm_refunds' => $o['priorRefunds']] : []);

        $this->orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $this->orderRepository->method('get')->willReturn($this->order);
        $orderResult = $this->createStub(\Magento\Sales\Api\Data\OrderSearchResultInterface::class);
        $orderResult->method('getItems')->willReturn($o['orderFound'] ? [$this->order] : []);
        $this->orderRepository->method('getList')->willReturn($orderResult);

        // The first lookup is by the refund id, the second by order id
        $calls = 0;
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->transactionRepository->method('getList')->willReturnCallback(
            function () use (&$calls, $o) {
                $calls++;
                $result = $this->createStub(TransactionSearchResultInterface::class);
                $found  = $o['foundById'] || $calls > 1;
                $result->method('getItems')->willReturn($found ? [$this->transaction] : []);

                return $result;
            }
        );

        $this->buildController();
    }

    /**
     * A partial refund adds a comment with the running total and leaves the order and credit memos alone
     */
    public function testPartialRefundCommentsAndKeepsOrderOpen(): void
    {
        $this->arrangeRefund(100); // $1.00 of $100.00, in cents

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with(
                'PayPal/Venmo partial refund of 1.00 (status 161, reason 4005): 1.00 of 100.00 refunded so far.'
                . ' The order stays open; create a credit memo if needed.'
            );
        $this->transaction->expects($this->exactly(2))->method('setAdditionalInformation');
        $this->transaction->expects($this->never())->method('setTxnType');
        $this->creditMemoService->expects($this->never())->method('refund');
        $this->transactionRepository->expects($this->once())->method('save')->with($this->transaction);
        $this->result->expects($this->once())->method('setHttpResponseCode')->with(200);

        $this->controller->execute();

        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order->getState());
    }

    /**
     * A partial refund that a Magento credit memo has already credited gives no advice to create one
     */
    public function testPartialRefundAlreadyCreditedInMagentoGivesNoAdvice(): void
    {
        $this->arrangeRefund(100); // $1.00 of $100.00, in cents
        $this->order->setTotalRefunded(1.00);

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal/Venmo partial refund of 1.00 (status 161, reason 4005): 1.00 of 100.00 refunded so far.');

        $this->controller->execute();

        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order->getState());
    }

    /**
     * The refunds seen so far are added up, so the refund that completes the total refunds the order in full
     */
    public function testRefundsAreSummedAndTheFinalOneCancelsAndCreditMemosOffline(): void
    {
        $invoice    = $this->createStub(Invoice::class);
        $creditMemo = $this->createStub(\Magento\Sales\Model\Order\Creditmemo::class);
        $this->arrangeRefund(7000, ['priorRefunds' => ['earlier-refund' => 3000], 'invoices' => [$invoice]]);
        $this->creditMemoFactory->method('createByInvoice')->willReturn($creditMemo);

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal/Venmo refunded in full (status 161, reason 4005): 100.00 of 100.00 refunded');
        $this->transaction->expects($this->once())->method('setTxnType')->with(TransactionInterface::TYPE_REFUND);
        // Offline, because Fortis has already returned the money
        $this->creditMemoService->expects($this->once())->method('refund')->with($creditMemo, true);
        $this->transactionRepository->expects($this->once())->method('save')->with($this->transaction);

        $this->controller->execute();

        $this->assertSame(Order::STATE_CANCELED, $this->order->getState());
        $this->assertSame(Order::STATE_CANCELED, $this->order->getStatus());
    }

    /**
     * A single refund of the whole amount refunds the order in full
     */
    public function testSingleFullRefundCancelsOrder(): void
    {
        $this->arrangeRefund(10000); // $100.00 in cents

        $this->transaction->expects($this->once())->method('setTxnType')->with(TransactionInterface::TYPE_REFUND);

        $this->controller->execute();

        $this->assertSame(Order::STATE_CANCELED, $this->order->getState());
    }

    /**
     * No credit memo is created when the order can no longer be credited
     */
    public function testFullRefundSkipsCreditMemoWhenOrderCannotBeCredited(): void
    {
        $this->arrangeRefund(10000, ['canCreditmemo' => false, 'invoices' => [$this->createStub(Invoice::class)]]);

        $this->creditMemoService->expects($this->never())->method('refund');

        $this->controller->execute();

        $this->assertSame(Order::STATE_CANCELED, $this->order->getState());
    }

    /**
     * A full refund started from a Magento credit memo has already closed the order, which stays closed
     */
    public function testFullRefundKeepsAnOrderThatMagentoAlreadyClosed(): void
    {
        $this->arrangeRefund(10000, ['canCreditmemo' => false]);
        $this->order->setState(Order::STATE_CLOSED);
        $this->order->setStatus(Order::STATE_CLOSED);

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal/Venmo refunded in full (status 161, reason 4005): 100.00 of 100.00 refunded');
        $this->creditMemoService->expects($this->never())->method('refund');

        $this->controller->execute();

        $this->assertSame(Order::STATE_CLOSED, $this->order->getState());
        $this->assertSame(Order::STATE_CLOSED, $this->order->getStatus());
    }

    /**
     * A refund recorded in place on the sale is matched by its transaction id
     */
    public function testRefundRecordedOnTheSaleIsFoundByTransactionId(): void
    {
        // No order matches the description, so only the transaction id can lead to the order
        $this->arrangeRefund(10000, ['foundById' => true, 'orderFound' => false]);

        $this->transactionRepository->expects($this->once())->method('save')->with($this->transaction);

        $this->controller->execute();

        $this->assertSame(Order::STATE_CANCELED, $this->order->getState());
    }

    /**
     * A refund that Fortis sends again is not counted twice
     */
    public function testRepeatedRefundIsNotCountedTwice(): void
    {
        $this->arrangeRefund(100, ['priorRefunds' => [self::REFUND_TXN_ID => 100]]);

        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->transactionRepository->expects($this->never())->method('save');
        $this->result->expects($this->once())->method('setHttpResponseCode')->with(200);

        $this->controller->execute();
    }

    /**
     * A refund that Fortis cannot confirm is rejected, because the webhook body is not authenticated
     */
    public function testUnverifiedRefundIsRejected(): void
    {
        $this->arrangeRefund(10000, ['verified' => false]);

        $this->result->expects($this->once())->method('setHttpResponseCode')->with(403);
        $this->transactionRepository->expects($this->never())->method('save');

        $this->controller->execute();

        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order->getState());
    }

    /**
     * A refund whose order number matches no order is answered with 404
     */
    public function testRefundWithoutAMatchingOrderIsNotFound(): void
    {
        $this->arrangeRefund(100, ['orderFound' => false]);

        $this->result->expects($this->once())->method('setHttpResponseCode')->with(404);
        $this->transactionRepository->expects($this->never())->method('save');

        $this->controller->execute();
    }

    /**
     * A refund naming an order paid with another method is ignored
     */
    public function testRefundForAnotherPaymentMethodIsNotFound(): void
    {
        $this->arrangeRefund(100, ['method' => 'checkmo']);

        $this->result->expects($this->once())->method('setHttpResponseCode')->with(404);
        $this->order->expects($this->never())->method('addCommentToStatusHistory');

        $this->controller->execute();
    }

    /**
     * A declined sale (reason 4002) on an unpaid order cancels it and is never invoiced
     */
    public function testDeclinedSaleCancelsUnpaidOrderAndIsNotInvoiced(): void
    {
        $this->sendWebhook(151, 4002);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->buildController();

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal/Venmo payment declined (status 151, reason 4002)');

        $this->controller->execute();

        $this->assertSame(Order::STATE_CANCELED, $this->order->getState());
    }

    /**
     * A declined order-started notice (reason 4002) on an unpaid order cancels it
     */
    public function testDeclinedOrderStartedCancelsUnpaidOrder(): void
    {
        $this->sendWebhook(152, 4002);

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal/Venmo payment declined (status 152, reason 4002)');

        $this->controller->execute();

        $this->assertSame(Order::STATE_CANCELED, $this->order->getState());
    }

    /**
     * A decline never cancels an order that has already been invoiced
     */
    public function testDeclineKeepsAnOrderThatIsAlreadyInvoiced(): void
    {
        $this->order = $this->buildOrder([$this->createStub(Invoice::class)]);
        $this->order->setState(Order::STATE_PROCESSING);
        $this->orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $this->orderRepository->method('get')->willReturn($this->order);
        $this->buildController();
        $this->sendWebhook(152, 4002);

        $this->controller->execute();

        $this->assertSame(Order::STATE_PROCESSING, $this->order->getState());
    }

    /**
     * An order started at PayPal with reason 4001 (pending) is held, not cancelled
     */
    public function testPendingOrderStartedHoldsOrderAndDoesNotCancelIt(): void
    {
        $this->sendWebhook(152, 4001);

        $this->controller->execute();

        $this->assertSame(Order::STATE_HOLDED, $this->order->getState());
    }

    /**
     * A sale that is still pending (reason 4001) is not invoiced: it holds the order until Fortis reports it completed
     */
    public function testPendingSaleHoldsOrderAndIsNotInvoiced(): void
    {
        $this->sendWebhook(151, 4001);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->buildController();

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal/Venmo payment pending (status 151, reason 4001)');

        $this->controller->execute();

        $this->assertSame(Order::STATE_HOLDED, $this->order->getState());
    }

    /**
     * A sale with no reason Fortis recognises is never taken as paid
     */
    public function testSaleWithAnUnknownReasonIsNotInvoiced(): void
    {
        $this->sendWebhook(151, 0);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->buildController();

        $this->controller->execute();

        $this->assertSame(Order::STATE_HOLDED, $this->order->getState());
    }

    /**
     * A sale completed (reason 4003) invoices the order
     */
    public function testCompletedSaleSettlesOrder(): void
    {
        $this->sendWebhook(151, 4003);
        $this->stubInvoiceFlow();

        $this->transaction->expects($this->once())
            ->method('setTxnType')
            ->with(TransactionInterface::TYPE_CAPTURE);

        $this->controller->execute();

        $this->assertSame(Order::STATE_PROCESSING, $this->order->getState());
    }

    /**
     * An order that a pending notice put on hold is invoiced when the sale completes, not skipped as invoiced
     */
    public function testCompletedSaleInvoicesAnOrderThatWasHeldWhilePending(): void
    {
        // Like Magento, an order on hold cannot be invoiced
        $this->order = $this->buildOrder(
            [],
            true,
            'fortis',
            fn(Order $order) => $order->getState() !== Order::STATE_HOLDED
        );
        $this->order->setState(Order::STATE_HOLDED);
        $this->order->setStatus(Order::STATE_HOLDED);
        $this->orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $this->orderRepository->method('get')->willReturn($this->order);
        $this->buildController();
        $this->sendWebhook(151, 4003);
        $this->stubInvoiceFlow();

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal payment completed (status 151, reason 4003)');

        $this->controller->execute();

        $this->assertSame(Order::STATE_PROCESSING, $this->order->getState());
    }

    /**
     * A JSON scalar body is rejected instead of being read as an array
     *
     * @param string $body
     */
    #[DataProvider('scalarBodyProvider')]
    public function testScalarBodyIsRejected(string $body): void
    {
        $this->request->method('getContent')->willReturn($body);

        $this->result->expects($this->once())->method('setHttpResponseCode')->with(422);
        $this->transactionRepository->expects($this->never())->method('save');

        $this->controller->execute();
    }

    /**
     * Bodies that decode to a scalar
     *
     * @return array<string, array{string}>
     */
    public static function scalarBodyProvider(): array
    {
        return [
            'false'  => ['false'],
            'zero'   => ['0'],
            'string' => ['"UPDATE"'],
            'number' => ['42'],
        ];
    }

    /**
     * A data member that is not a JSON object is rejected
     */
    public function testScalarDataMemberIsRejected(): void
    {
        $this->request->method('getContent')->willReturn(json_encode(['type' => 'UPDATE', 'data' => '"apm"']));

        $this->result->expects($this->once())->method('setHttpResponseCode')->with(422);
        $this->transactionRepository->expects($this->never())->method('save');

        $this->controller->execute();
    }

    /**
     * Only sentinels reach the log; the payment details in the body never do
     */
    public function testLogsSentinelsOnly(): void
    {
        $this->request->method('getContent')->willReturn(json_encode([
            'type' => 'UPDATE',
            'data' => [
                'id'             => self::FORTIS_TXN_ID,
                'payment_method' => 'apm',
                'status_code'    => 152,
                'reason_code_id' => 4001,
                'billing_email'  => 'buyer@example.com',
            ],
        ]));
        $logger = $this->createMock(LoggerInterface::class);
        $logged = [];
        $logger->method('info')->willReturnCallback(function ($message, $context = []) use (&$logged) {
            $logged[] = $message . json_encode($context);
        });
        $this->logger = $logger;
        $this->buildController();

        $this->controller->execute();

        $this->assertSame(
            ['APM Hook reached{"type":"UPDATE","id":"fortis-apm-txn-1","status_code":152,"reason_code_id":4001}'],
            $logged
        );
    }

    /**
     * The webhook note stored on the Magento transaction holds sentinels, not the raw body
     */
    public function testStoredWebhookNoteHoldsSentinelsOnly(): void
    {
        $this->request->method('getContent')->willReturn(json_encode([
            'type' => 'UPDATE',
            'data' => [
                'id'             => self::FORTIS_TXN_ID,
                'payment_method' => 'apm',
                'status_code'    => 152,
                'billing_email'  => 'buyer@example.com',
            ],
        ]));

        $this->transaction->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(
                'webhook_update_info',
                ['{"type":"UPDATE","id":"fortis-apm-txn-1","status_code":152,"reason_code_id":0}']
            );

        $this->controller->execute();
    }
    /**
     * A repository that finds nothing for the first looks and then the transaction, as when Fortis's webhook
     * arrives before the return from PayPal has recorded the sale
     *
     * @param int $emptyLooks How many looks find nothing first (null: always)
     * @return TransactionRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private function lateRepository(?int $emptyLooks)
    {
        $looks = 0;
        $repository = $this->createMock(TransactionRepositoryInterface::class);
        $repository->method('getList')->willReturnCallback(function () use (&$looks, $emptyLooks) {
            $looks++;
            $result = $this->createStub(TransactionSearchResultInterface::class);
            $result->method('getItems')->willReturn(
                $emptyLooks !== null && $looks > $emptyLooks ? [$this->transaction] : []
            );

            return $result;
        });

        return $repository;
    }

    /**
     * A sale webhook that arrives before the return from PayPal has recorded the transaction waits for it, then
     * adds its note as usual
     */
    public function testSaleWebhookWaitsForTheReturnToRecordTheTransaction(): void
    {
        $this->sendWebhook(151, 4003);
        $this->stubInvoiceFlow();
        $this->buildController($this->lateRepository(3));

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('PayPal payment completed (status 151, reason 4003)');
        $this->result->expects($this->once())->method('setHttpResponseCode')->with(200);

        $this->controller->execute();

        $this->assertSame(3, $this->controller->pauses, 'it paused once for each look that found nothing');
    }

    /**
     * A webhook for a sale that is already recorded does not wait at all
     */
    public function testSaleWebhookForARecordedSaleDoesNotWait(): void
    {
        $this->sendWebhook(151, 4003);
        $this->stubInvoiceFlow();

        $this->controller->execute();

        $this->assertSame(0, $this->controller->pauses);
    }

    /**
     * A sale that is never recorded (no return from PayPal) is still answered with 404, after the bounded wait
     */
    public function testSaleWebhookGivesUpWhenTheSaleIsNeverRecorded(): void
    {
        $this->sendWebhook(151, 4003);
        $repository = $this->lateRepository(null);
        $repository->expects($this->exactly(ApmHook::RETURN_WAIT_ATTEMPTS + 1))->method('getList');
        $this->buildController($repository);

        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->result->expects($this->once())->method('setHttpResponseCode')->with(404);

        $this->controller->execute();

        $this->assertSame(ApmHook::RETURN_WAIT_ATTEMPTS, $this->controller->pauses);
    }
}
