<?php
/**
 * Unit tests for the Authorise redirect controller
 * Covers the order history note written when a payment is not authorised
 */

namespace Fortispay\Fortis\Test\Unit\Controller\Redirect;

use Fortispay\Fortis\Controller\Redirect\Authorise;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\Fortis;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Service\FortisMethodService;
use Fortispay\Fortis\Service\MagentoOrderService;
use Fortispay\Fortis\Service\TransactionVerifier;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// The partial Order, Payment, Builder and Transaction doubles act as stubs where a test sets no expectations on them
#[AllowMockObjectsWithoutExpectations]
class AuthoriseTest extends TestCase
{
    /**
     * @var Order|\PHPUnit\Framework\MockObject\MockObject
     */
    private $order;

    /**
     * @var Authorise
     */
    private Authorise $controller;

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
                'getBaseCurrency', 'cancel', 'addCommentToStatusHistory', 'addStatusToHistory'])
            ->getMock();
        $this->order->method('getId')->willReturn(42);
        $this->order->method('getPayment')->willReturn($payment);
        $this->order->method('getAllStatusHistory')->willReturn([]);
        $this->order->method('getGrandTotal')->willReturn(25.00); // $25.00, 2500 cents
        $this->order->method('getIncrementId')->willReturn('000000042');
        $this->order->method('getBaseCurrency')->willReturn($baseCurrency);

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

        // $25.00 in cents, declined with reason code 1500 (DENY)
        $fortisApi = $this->createStub(FortisApi::class);
        $fortisApi->method('getTransaction')->willReturn((object)[
            'data' => (object)[
                'id'                     => 'fortis-txn-1',
                'reason_code_id'         => 1500,
                'transaction_amount'     => 2500,
                'product_transaction_id' => 'product-1',
                'payment_method'         => 'cc',
            ],
        ]);

        $verifier = $this->createStub(TransactionVerifier::class);
        $verifier->method('verifyTransactionById')->willReturn(['verified' => true, 'errors' => []]);

        $transaction = $this->createStub(Transaction::class);
        $builder = $this->getMockBuilder(Builder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setPayment', 'setOrder', 'setTransactionId', 'setAdditionalInformation', 'setFailSafe',
                'build'])
            ->getMock();
        foreach (['setPayment', 'setOrder', 'setTransactionId', 'setAdditionalInformation', 'setFailSafe'] as $step) {
            $builder->method($step)->willReturnSelf();
        }
        $builder->method('build')->willReturn($transaction);

        $this->controller = new Authorise(
            $this->createStub(PageFactory::class),
            $checkoutSession,
            $this->createStub(LoggerInterface::class),
            $this->createStub(Fortis::class),
            $this->createStub(Config::class),
            $this->createStub(OrderRepositoryInterface::class),
            $storeManager,
            $this->createStub(OrderSender::class),
            $builder,
            $this->order,
            $request,
            $resultFactory,
            $this->createStub(ManagerInterface::class),
            $this->createStub(JsonFactory::class),
            $this->createStub(EventManager::class),
            $this->createStub(CountryFactory::class),
            $this->createStub(CountryCollectionFactory::class),
            $this->createStub(FortisMethodService::class),
            $fortisApi,
            $this->createStub(MagentoOrderService::class),
            $verifier,
            $this->createStub(QuoteRepository::class)
        );
    }

    /**
     * A declined payment cancels the order, then records the failure as the note, not as the status
     */
    public function testDeclinedPaymentAddsFailureCommentAfterCancel(): void
    {
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

        $this->controller->execute();

        $this->assertSame(['cancel', 'comment: Failed: Not Authorised: DENY '], $calls);
    }
}
