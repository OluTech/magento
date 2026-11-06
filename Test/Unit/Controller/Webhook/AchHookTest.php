<?php
/**
 * Unit tests for the ACH webhook controller
 * Covers the order history comments, the final order state and the persistence of the Magento transaction
 */

namespace Fortispay\Fortis\Test\Unit\Controller\Webhook;

use Fortispay\Fortis\Controller\Webhook\AchHook;
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
class AchHookTest extends TestCase
{
    private const FORTIS_TXN_ID = 'fortis-ach-txn-1';

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

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
     * @var AchHook
     */
    private AchHook $controller;

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

        $this->transaction = $this->getMockBuilder(Transaction::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderId', 'getTransactionId', 'getAdditionalInformation', 'setAdditionalInformation',
                'setTxnType'])
            ->getMock();
        $this->transaction->method('getOrderId')->willReturn(42);
        $this->transaction->method('getTransactionId')->willReturn(7);
        $this->transaction->method('getAdditionalInformation')->willReturn([]);

        $this->order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getGrandTotal', 'getIncrementId', 'getInvoiceCollection', 'addCommentToStatusHistory',
                'addStatusToHistory'])
            ->getMock();
        $this->order->method('getGrandTotal')->willReturn(100.00); // $100.00, 10000 cents
        $this->order->method('getIncrementId')->willReturn('000000042');
        $this->order->method('getInvoiceCollection')->willReturn([]);
        $this->order->setState(Order::STATE_PENDING_PAYMENT);
        $this->order->setStatus(Order::STATE_PENDING_PAYMENT);

        $this->orderRepository->method('get')->willReturn($this->order);

        $searchResult = $this->createStub(TransactionSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$this->transaction]);
        $this->transactionRepository->method('getList')->willReturn($searchResult);

        $this->buildController();
    }

    /**
     * Build the controller from the current doubles
     */
    private function buildController(): void
    {
        $searchCriteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createStub(SearchCriteriaInterface::class));

        $verifier = $this->createStub(TransactionVerifier::class);
        $verifier->method('verifyTransactionById')->willReturn(['verified' => true, 'errors' => []]);

        $resultFactory = $this->createStub(RawFactory::class);
        $resultFactory->method('create')->willReturn($this->result);

        $this->controller = new AchHook(
            $this->request,
            $this->createStub(ResourceConnection::class),
            $this->transactionRepository,
            $this->logger,
            $resultFactory,
            $this->config,
            $this->createStub(OrderSender::class),
            $this->invoiceService,
            $this->createStub(InvoiceSender::class),
            $this->dbTransaction,
            $this->createStub(CreditmemoService::class),
            $this->createStub(CreditmemoFactory::class),
            $this->orderRepository,
            $searchCriteriaBuilder,
            $verifier
        );
    }

    /**
     * Point the request at an ACH webhook carrying the given Fortis status code
     *
     * @param int $statusCode
     */
    private function sendWebhook(int $statusCode): void
    {
        $this->request->method('getContent')->willReturn(json_encode([
            'type' => 'UPDATE',
            'data' => ['id' => self::FORTIS_TXN_ID, 'payment_method' => 'ach', 'status_code' => $statusCode],
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
     * A settled debit stores the message as the comment, keeps the processing state and saves the capture type
     */
    public function testSettledWebhookAddsCommentAndSavesCaptureTransaction(): void
    {
        $this->sendWebhook(134);
        $this->stubInvoiceFlow();

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Payment succeeded. Status code: 134 Settled');
        $this->order->expects($this->never())->method('addStatusToHistory');
        $this->transaction->expects($this->once())
            ->method('setTxnType')
            ->with(TransactionInterface::TYPE_CAPTURE);
        $this->transaction->expects($this->once())->method('setAdditionalInformation');
        $this->transactionRepository->expects($this->once())
            ->method('save')
            ->with($this->transaction);
        $this->result->expects($this->once())->method('setHttpResponseCode')->with(200);

        $this->controller->execute();

        $this->assertSame(Order::STATE_PROCESSING, $this->order->getState());
        $this->assertSame(Order::STATE_PROCESSING, $this->order->getStatus());
    }

    /**
     * The customer and invoice emails are recorded as comments, not as statuses
     */
    public function testSettledWebhookRecordsEmailNotesAsComments(): void
    {
        $this->sendWebhook(134);
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
                'Payment succeeded. Status code: 134 Settled',
            ],
            $comments
        );
    }

    /**
     * A failed or declined debit cancels the order with the failure message as the comment
     *
     * @param int $statusCode
     * @param string $expectedComment
     */
    #[DataProvider('failureStatusProvider')]
    public function testFailureWebhookAddsCommentAndCancelsOrder(int $statusCode, string $expectedComment): void
    {
        $this->sendWebhook($statusCode);

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with($expectedComment);
        $this->order->expects($this->never())->method('addStatusToHistory');
        $this->transaction->expects($this->never())->method('setTxnType');
        $this->transactionRepository->expects($this->once())->method('save')->with($this->transaction);

        $this->controller->execute();

        $this->assertSame(Order::STATE_CANCELED, $this->order->getState());
        $this->assertSame(Order::STATE_CANCELED, $this->order->getStatus());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function failureStatusProvider(): array
    {
        return [
            'voided'   => [201, 'Payment failed. Status code: 201 Voided'],
            'declined' => [301, 'Payment failed. Status code: 301 Declined'],
        ];
    }

    /**
     * A charge back cancels the order, records the refund comment and saves the refund transaction type
     */
    public function testChargeBackWebhookAddsCommentAndSavesRefundTransaction(): void
    {
        $this->sendWebhook(331);

        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Payment refunded. Status code: 331 Charged Back');
        $this->order->expects($this->never())->method('addStatusToHistory');
        $this->transaction->expects($this->once())
            ->method('setTxnType')
            ->with(TransactionInterface::TYPE_REFUND);
        $this->transactionRepository->expects($this->once())->method('save')->with($this->transaction);

        $this->controller->execute();

        $this->assertSame(Order::STATE_CANCELED, $this->order->getState());
        $this->assertSame(Order::STATE_CANCELED, $this->order->getStatus());
    }

    /**
     * The pending updates are persisted on the transaction and leave the order untouched
     *
     * @param int $statusCode
     */
    #[DataProvider('pendingStatusProvider')]
    public function testPendingWebhookSavesWebhookInfoOnly(int $statusCode): void
    {
        $this->sendWebhook($statusCode);

        $this->transaction->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(
                'webhook_update_info',
                $this->callback(fn ($info) => count($info) === 1 && str_contains($info[0], self::FORTIS_TXN_ID))
            );
        $this->transaction->expects($this->never())->method('setTxnType');
        $this->transactionRepository->expects($this->once())->method('save')->with($this->transaction);
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->order->expects($this->never())->method('addStatusToHistory');

        $this->controller->execute();

        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order->getState());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function pendingStatusProvider(): array
    {
        return [
            'pending origination' => [131],
            'originating'         => [132],
            'originated'          => [133],
        ];
    }

    /**
     * An unhandled status code changes nothing, so the transaction is not saved
     */
    public function testUnknownStatusDoesNotSaveTransaction(): void
    {
        $this->sendWebhook(999);

        $this->transactionRepository->expects($this->never())->method('save');
        $this->order->expects($this->never())->method('addCommentToStatusHistory');

        $this->controller->execute();
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
        $this->request->method('getContent')->willReturn(json_encode(['type' => 'UPDATE', 'data' => '"ach"']));

        $this->result->expects($this->once())->method('setHttpResponseCode')->with(422);
        $this->transactionRepository->expects($this->never())->method('save');

        $this->controller->execute();
    }

    /**
     * Only sentinels reach the log; the account details in the body never do
     */
    public function testLogsSentinelsOnly(): void
    {
        $this->request->method('getContent')->willReturn(json_encode([
            'type' => 'UPDATE',
            'data' => [
                'id'             => self::FORTIS_TXN_ID,
                'payment_method' => 'ach',
                'status_code'    => 132,
                'account_number' => '000123456789',
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
            ['ACH Hook has been reached{"type":"UPDATE","id":"fortis-ach-txn-1","status_code":132,"reason_code_id":0}'],
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
                'payment_method' => 'ach',
                'status_code'    => 132,
                'account_number' => '000123456789',
            ],
        ]));

        $this->transaction->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(
                'webhook_update_info',
                ['{"type":"UPDATE","id":"fortis-ach-txn-1","status_code":132,"reason_code_id":0}']
            );

        $this->controller->execute();
    }
}
