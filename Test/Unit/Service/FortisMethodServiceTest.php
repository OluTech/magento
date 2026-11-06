<?php
/**
 * Unit tests for FortisMethodService
 * Covers refundOnline for PayPal/Venmo (APM) and card payments
 */

namespace Fortispay\Fortis\Test\Unit\Service;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\CurrencyOptions;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Service\CheckoutProcessor;
use Fortispay\Fortis\Service\FortisMethodService;
use Fortispay\Fortis\Service\MagentoOrderService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\UrlInterface;
use Magento\Catalog\Model\Product;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Model\PaymentTokenFactory;
use Magento\Vault\Model\ResourceModel\PaymentToken as PaymentTokenResourceModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// The partial Order double acts as a stub in the tests that only look at the order's state afterwards
#[AllowMockObjectsWithoutExpectations]
class FortisMethodServiceTest extends TestCase
{
    private const CREDENTIAL = 'decrypted-credential';

    /**
     * @var FortisApi|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fortisApi;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var Order|\PHPUnit\Framework\MockObject\MockObject
     */
    private $order;

    /**
     * @var FortisMethodService
     */
    private FortisMethodService $service;

    protected function setUp(): void
    {
        $this->fortisApi = $this->createMock(FortisApi::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // The credentials come from scope config and are decrypted; one value serves both
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn(self::CREDENTIAL);

        $this->order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStatus', 'getIncrementId', 'getOrderCurrencyCode', 'addCommentToStatusHistory',
                'getAllItems'])
            ->getMock();
        $this->order->method('getStatus')->willReturn(Order::STATE_PROCESSING);
        $this->order->method('getIncrementId')->willReturn('000000042');
        $this->order->method('getOrderCurrencyCode')->willReturn('USD');

        $this->service = new FortisMethodService(
            $this->createStub(Config::class),
            $this->logger,
            $this->createStub(File::class),
            $this->createStub(DirectoryList::class),
            $this->createStub(CheckoutSession::class),
            $this->createStub(UrlInterface::class),
            $encryptor,
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(PaymentTokenRepositoryInterface::class),
            $this->createStub(PaymentTokenManagementInterface::class),
            $this->createStub(PaymentTokenFactory::class),
            $this->createStub(PaymentTokenResourceModel::class),
            $this->createStub(OrderRepositoryInterface::class),
            $this->createStub(FileDriver::class),
            $this->fortisApi,
            $this->createStub(Builder::class),
            $this->createStub(MagentoOrderService::class),
            $this->createStub(CheckoutProcessor::class),
            $this->createStub(CurrencyOptions::class)
        );
    }

    /**
     * Build a payment whose Fortis transaction details are the given JSON
     *
     * @param array $rawDetails
     * @return Payment|\PHPUnit\Framework\MockObject\MockObject
     */
    private function buildPayment(array $rawDetails)
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getOrder')->willReturn($this->order);
        $payment->method('getAdditionalInformation')->willReturn(['raw_details_info' => json_encode($rawDetails)]);

        return $payment;
    }

    /**
     * A PayPal/Venmo refund skips the card surcharge lookup and sends the order number as the description
     */
    public function testApmRefundSkipsSurchargeAndSendsTheOrderNumber(): void
    {
        $payment = $this->buildPayment(['id' => 'sale-1', 'payment_method' => 'apm']);

        $this->fortisApi->expects($this->never())->method('calculateSurcharge');
        $this->fortisApi->expects($this->once())
            ->method('refundTransactionAmount')
            ->with(
                [
                    'transaction_amount' => 100, // $1.00 in cents
                    'transactionId'      => 'sale-1',
                    'description'        => '000000042',
                    'tax'                => 0,
                ],
                self::CREDENTIAL,
                self::CREDENTIAL
            )
            ->willReturn(json_encode(['data' => ['id' => 'refund-1', 'status_code' => 161, 'reason_code_id' => 4005]]));
        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Order Successfully Refunded with Transaction Id - refund-1');

        $this->assertTrue($this->service->refundOnline($payment, 1.00));
    }

    /**
     * A PayPal/Venmo refund that Fortis does not report as refunded is an error, noted once on the order and logged
     */
    public function testApmRefundNotConfirmedByFortisFails(): void
    {
        $payment = $this->buildPayment(['id' => 'sale-1', 'payment_method' => 'apm']);

        $this->fortisApi->expects($this->once())
            ->method('refundTransactionAmount')
            ->willReturn(json_encode(['data' => ['id' => 'refund-1', 'status_code' => 151, 'reason_code_id' => 4003]]));
        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Refund not successful');
        $this->logger->expects($this->once())->method('error')->with('Refund failed: Refund not successful.');

        try {
            $this->service->refundOnline($payment, 1.00);
            $this->fail('Expected a LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertSame('Refund not successful. Please contact support.', $e->getMessage());
        }
    }

    /**
     * An error from the Fortis API during a refund is caught: noted once on the order, logged, and shown generically
     */
    public function testRefundThatFortisRejectsFailsGenerically(): void
    {
        $payment = $this->buildPayment(['id' => 'sale-1', 'payment_method' => 'apm']);

        $this->fortisApi->expects($this->once())
            ->method('refundTransactionAmount')
            ->willThrowException(new LocalizedException(__('Transaction already refunded')));
        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Refund not successful');
        $this->logger->expects($this->once())->method('error')->with('Refund failed: Transaction already refunded');

        try {
            $this->service->refundOnline($payment, 1.00);
            $this->fail('Expected a LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertSame('Refund not successful. Please contact support.', $e->getMessage());
        }
    }

    /**
     * A card refund still prices the surcharge from the card and needs reason 1000
     */
    public function testCardRefundStillCalculatesSurchargeAndNeedsReason1000(): void
    {
        $payment = $this->buildPayment([
            'id'             => 'sale-2',
            'payment_method' => 'cc',
            'first_six'      => '411111',
            'billing_zip'    => '32960',
        ]);

        $this->fortisApi->expects($this->once())
            ->method('calculateSurcharge')
            ->willReturn(json_encode(['data' => []]));
        $this->fortisApi->expects($this->once())
            ->method('refundTransactionAmount')
            ->with($this->callback(fn(array $intent) => array_key_exists('product_transaction_id', $intent)
                && $intent['transactionId'] === 'sale-2'), self::CREDENTIAL, self::CREDENTIAL)
            ->willReturn(json_encode(['data' => ['id' => 'refund-2', 'auth_code' => 'A1', 'reason_code_id' => 1000]]));
        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Order Successfully Refunded with Transaction Id - refund-2 Auth Code - A1');

        $this->assertTrue($this->service->refundOnline($payment, 1.00));
    }

    /**
     * Build an order item whose product is virtual or physical
     *
     * @param bool $virtual
     * @return Order\Item
     */
    private function buildItem(bool $virtual)
    {
        $product = $this->createStub(Product::class);
        $product->method('isVirtual')->willReturn($virtual);
        $item = $this->createStub(Order\Item::class);
        $item->method('getProduct')->willReturn($product);

        return $item;
    }

    /**
     * Refund an order that is Complete, with the given items, and return it
     *
     * @param array $items
     * @return Order
     */
    private function refundCompleteOrderWithItems(array $items): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIncrementId', 'getOrderCurrencyCode', 'addCommentToStatusHistory', 'getAllItems'])
            ->getMock();
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getAllItems')->willReturn($items);
        $order->method('addCommentToStatusHistory')->willReturnSelf();
        $order->setState(Order::STATE_COMPLETE);
        $order->setStatus(Order::STATE_COMPLETE);
        $this->order = $order;

        $this->fortisApi->expects($this->once())
            ->method('refundTransactionAmount')
            ->willReturn(json_encode(['data' => ['id' => 'refund-1', 'status_code' => 161]]));

        $this->service->refundOnline($this->buildPayment(['id' => 'sale-1', 'payment_method' => 'apm']), 1.00);

        return $order;
    }

    /**
     * A virtual order keeps its Complete state, which Magento never recomputes after a refund
     */
    public function testRefundLeavesTheStateOfAVirtualOrderAlone(): void
    {
        $order = $this->refundCompleteOrderWithItems([$this->buildItem(true)]);

        $this->assertSame(Order::STATE_COMPLETE, $order->getState());
        $this->assertSame(Order::STATE_COMPLETE, $order->getStatus());
    }

    /**
     * An order with a physical item is still put in Processing for the refund
     */
    public function testRefundPutsAnOrderWithAPhysicalItemInProcessing(): void
    {
        $order = $this->refundCompleteOrderWithItems([$this->buildItem(true), $this->buildItem(false)]);

        $this->assertSame(Order::STATE_PROCESSING, $order->getState());
        $this->assertSame(Order::STATE_PROCESSING, $order->getStatus());
    }

    /**
     * A refund of an order paid in another currency is sent to Fortis in that currency, not the base currency
     */
    public function testRefundIsSentInTheOrderCurrencyNotTheBaseCurrency(): void
    {
        $creditMemo = $this->createStub(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditMemo->method('getGrandTotal')->willReturn(22.50); // EUR, what the customer paid
        $creditMemo->method('getBaseGrandTotal')->willReturn(25.00); // USD, what Magento hands the payment method
        $payment = $this->createStub(Payment::class);
        $payment->method('getOrder')->willReturn($this->order);
        $payment->method('getCreditmemo')->willReturn($creditMemo);
        $rawDetails = json_encode(['id' => 'sale-1', 'payment_method' => 'apm']);
        $payment->method('getAdditionalInformation')->willReturn(['raw_details_info' => $rawDetails]);

        $this->fortisApi->expects($this->once())
            ->method('refundTransactionAmount')
            ->with(
                $this->callback(fn(array $intent) => $intent['transaction_amount'] === 2250),
                self::CREDENTIAL,
                self::CREDENTIAL
            )
            ->willReturn(json_encode(['data' => ['id' => 'refund-1', 'status_code' => 161]]));

        $this->assertTrue($this->service->refundOnline($payment, 25.00));
    }
}
