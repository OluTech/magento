<?php
/**
 * Unit tests for the Success redirect controller
 * Covers the order history notes written for pending ACH, pending PayPal and declined payments
 */

namespace Fortispay\Fortis\Test\Unit\Controller\Redirect;

use Fortispay\Fortis\Controller\Redirect\Success;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\Fortis;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Service\CheckoutProcessor;
use Fortispay\Fortis\Service\FortisMethodService;
use Fortispay\Fortis\Service\MagentoOrderService;
use Fortispay\Fortis\Service\TransactionVerifier;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\DB\Transaction as DbTransaction;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// The partial Order, Payment, Builder and Transaction doubles act as stubs where a test sets no expectations on them
#[AllowMockObjectsWithoutExpectations]
class SuccessTest extends TestCase
{
    /**
     * @var Order|\PHPUnit\Framework\MockObject\MockObject
     */
    private $order;

    /**
     * @var FortisApi|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fortisApi;

    protected function setUp(): void
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addTransactionCommentsToOrder', 'setParentTransactionId'])
            ->getMock();
        $payment->setData('id', 5);
        $payment->setData('additional_information', []);

        $baseCurrency = new class {
            /**
             * Formats an amount the way the base currency would
             *
             * @param float $amount
             * @return string
             */
            public function formatTxt($amount): string
            {
                return '$' . number_format($amount, 2);
            }
        };

        $this->order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getPayment', 'getAllStatusHistory', 'getGrandTotal', 'getIncrementId',
                'getBaseCurrency', 'hasInvoices', 'cancel', 'addCommentToStatusHistory', 'addStatusToHistory'])
            ->getMock();
        $this->order->method('getId')->willReturn(42);
        $this->order->method('hasInvoices')->willReturn(false);
        $this->order->method('getPayment')->willReturn($payment);
        $this->order->method('getAllStatusHistory')->willReturn([]);
        $this->order->method('getGrandTotal')->willReturn(25.00); // $25.00, 2500 cents
        $this->order->method('getIncrementId')->willReturn('000000042');
        $this->order->method('getBaseCurrency')->willReturn($baseCurrency);
        $this->order->setState(Order::STATE_PENDING_PAYMENT);
        $this->order->setStatus(Order::STATE_PENDING_PAYMENT);

        $this->fortisApi = $this->createStub(FortisApi::class);
    }

    /**
     * Build the controller once the test has said what Fortis returns for the transaction
     *
     * @param array $transaction
     */
    private function buildController(array $transaction): Success
    {
        // Amounts are integer cents: $25.00
        $this->fortisApi->method('getTransaction')->willReturn((object)['data' => (object)($transaction + [
            'id'                     => 'fortis-txn-1',
            'transaction_amount'     => 2500,
            'product_transaction_id' => 'product-1',
        ])]);

        $request = $this->createStub(Http::class);
        $request->method('getContent')->willReturn('');
        $request->method('getParams')->willReturn(['tid' => 'fortis-txn-1']);

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.example.com/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setUrl')->willReturnSelf();
        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($redirect);

        $checkoutSession = $this->createStub(CheckoutSession::class);
        $checkoutSession->method('getLastRealOrder')->willReturn($this->order);

        $verifier = $this->createStub(TransactionVerifier::class);
        $verifier->method('verifyTransactionById')->willReturn(['verified' => true, 'errors' => []]);

        $builder = $this->getMockBuilder(Builder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setPayment', 'setOrder', 'setTransactionId', 'setAdditionalInformation', 'setFailSafe',
                'build'])
            ->getMock();
        foreach (['setPayment', 'setOrder', 'setTransactionId', 'setAdditionalInformation', 'setFailSafe'] as $step) {
            $builder->method($step)->willReturnSelf();
        }
        $builder->method('build')->willReturn($this->createStub(Transaction::class));

        return new Success(
            $this->createStub(PageFactory::class),
            $checkoutSession,
            $this->createStub(LoggerInterface::class),
            $this->createStub(InvoiceService::class),
            $this->createStub(InvoiceSender::class),
            $this->createStub(Fortis::class),
            $this->createStub(Config::class),
            $this->createStub(OrderRepositoryInterface::class),
            $storeManager,
            $this->createStub(OrderSender::class),
            $builder,
            $this->createStub(DbTransaction::class),
            $this->order,
            $request,
            $resultFactory,
            $this->createStub(ManagerInterface::class),
            $this->createStub(JsonFactory::class),
            $this->createStub(EventManager::class),
            $this->createStub(CountryFactory::class),
            $this->createStub(CountryCollectionFactory::class),
            $this->createStub(FortisMethodService::class),
            $this->createStub(InvoiceRepositoryInterface::class),
            $this->fortisApi,
            $this->createStub(CheckoutProcessor::class),
            $this->createStub(MagentoOrderService::class),
            $verifier,
            $this->createStub(QuoteRepository::class),
            $this->createStub(SearchCriteriaBuilder::class)
        );
    }

    /**
     * A pending ACH debit or PayPal order puts the order On Hold, and the message is the note, not the status
     *
     * @param array $transaction
     * @param string $expectedComment
     */
    #[DataProvider('pendingPaymentProvider')]
    public function testPendingPaymentHoldsOrderAndAddsComment(array $transaction, string $expectedComment): void
    {
        $controller = $this->buildController($transaction);

        $stateWhenCommented = null;
        $this->order->expects($this->once())->method('addCommentToStatusHistory')->willReturnCallback(
            function ($comment) use ($expectedComment, &$stateWhenCommented) {
                $this->assertSame($expectedComment, (string)$comment);
                $stateWhenCommented = $this->order->getStatus();

                return null;
            }
        );
        $this->order->expects($this->never())->method('addStatusToHistory');

        $controller->execute();

        $this->assertSame(Order::STATE_HOLDED, $stateWhenCommented, 'the note is added after the order is held');
        $this->assertSame(Order::STATE_HOLDED, $this->order->getState());
        $this->assertSame(Order::STATE_HOLDED, $this->order->getStatus());
    }

    /**
     * @return array<string, array{array, string}>
     */
    public static function pendingPaymentProvider(): array
    {
        return [
            'ACH pending origination' => [
                ['payment_method' => 'ach', 'status_code' => 131],
                'ACH Transaction: Pending Origination',
            ],
            'PayPal order started'    => [
                ['payment_method' => 'apm', 'status_code' => 152],
                'PayPal payment pending (status 152 — awaiting PayPal confirmation)',
            ],
        ];
    }

    /**
     * A declined payment cancels the order, then records the failure as the note, not as the status
     */
    public function testDeclinedPaymentAddsFailureCommentAfterCancel(): void
    {
        // Status code 1500 is DENY
        $controller = $this->buildController(['payment_method' => 'cc', 'status_code' => 1500]);

        $calls = [];
        $this->order->expects($this->once())->method('cancel')->willReturnCallback(
            function () use (&$calls) {
                $calls[] = 'cancel';

                return $this->order;
            }
        );
        $this->order->expects($this->once())->method('addCommentToStatusHistory')->willReturnCallback(
            function ($comment) use (&$calls) {
                $calls[] = 'comment: ' . $comment;

                return null;
            }
        );
        $this->order->expects($this->never())->method('addStatusToHistory');

        $controller->execute();

        $this->assertSame(['cancel', 'comment: Failed: Not Authorised: DENY '], $calls);
    }
}
