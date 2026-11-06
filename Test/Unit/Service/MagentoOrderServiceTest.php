<?php
/**
 * Unit tests for MagentoOrderService
 * Tests surcharge application and refund data updates
 */

namespace Fortispay\Fortis\Test\Unit\Service;

use Exception;
use Fortispay\Fortis\Service\MagentoOrderService;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\ItemFactory;
use PHPUnit\Framework\TestCase;

class MagentoOrderServiceTest extends TestCase
{
    /**
     * @var MagentoOrderService
     */
    private MagentoOrderService $service;

    /**
     * @var ItemFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $itemFactoryMock;

    /**
     * @var OrderRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orderRepositoryMock;

    /**
     * @var OrderItemRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orderItemRepositoryMock;

    protected function setUp(): void
    {
        $this->itemFactoryMock = $this->createMock(ItemFactory::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->orderItemRepositoryMock = $this->createMock(OrderItemRepositoryInterface::class);

        $this->service = new MagentoOrderService(
            $this->itemFactoryMock,
            $this->orderRepositoryMock,
            $this->orderItemRepositoryMock
        );
    }

    /**
     * Test applying surcharge to order without invoice
     * Tests conversion from cents to currency units
     */
    public function testApplySurchargeWithoutInvoice(): void
    {
        $surchargeAmountCents = 300; // $3.00
        $expectedSurcharge = 3.00;

        $orderMock = $this->createConfiguredMock(Order::class, [
            'getSubtotal' => 100.00,
            'getBaseSubtotal' => 100.00,
            'getGrandTotal' => 110.00,
            'getBaseGrandTotal' => 110.00
        ]);

        $orderMock->expects($this->once())
            ->method('setData')
            ->with('custom_surcharge', $expectedSurcharge)
            ->willReturnSelf();

        $orderMock->expects($this->once())
            ->method('setSubtotal')
            ->with(103.00)
            ->willReturnSelf();

        $orderMock->expects($this->once())
            ->method('setBaseSubtotal')
            ->with(103.00)
            ->willReturnSelf();

        $orderMock->expects($this->once())
            ->method('setGrandTotal')
            ->with(113.00)
            ->willReturnSelf();

        $orderMock->expects($this->once())
            ->method('setBaseGrandTotal')
            ->with(113.00)
            ->willReturnSelf();

        $orderMock->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with($this->callback(function ($comment) use ($expectedSurcharge) {
                return strpos((string)$comment, (string)$expectedSurcharge) !== false;
            }))
            ->willReturnSelf();

        $this->orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($orderMock);

        $this->service->applySurcharge($orderMock, $surchargeAmountCents);
    }

    /**
     * Test applying surcharge to order with invoice
     */
    public function testApplySurchargeWithInvoice(): void
    {
        $surchargeAmountCents = 250; // $2.50
        $expectedSurcharge = 2.50;

        $orderMock = $this->createConfiguredMock(Order::class, [
            'getSubtotal' => 100.00,
            'getBaseSubtotal' => 100.00,
            'getGrandTotal' => 110.00,
            'getBaseGrandTotal' => 110.00
        ]);

        $invoiceMock = $this->createConfiguredMock(Invoice::class, [
            'getSubtotal' => 100.00,
            'getBaseSubtotal' => 100.00,
            'getGrandTotal' => 110.00,
            'getBaseGrandTotal' => 110.00
        ]);

        $orderMock->method('setData')->willReturnSelf();
        $orderMock->method('setSubtotal')->willReturnSelf();
        $orderMock->method('setBaseSubtotal')->willReturnSelf();
        $orderMock->method('setGrandTotal')->willReturnSelf();
        $orderMock->method('setBaseGrandTotal')->willReturnSelf();
        $orderMock->method('addCommentToStatusHistory')->willReturnSelf();

        $invoiceMock->expects($this->once())
            ->method('setSubtotal')
            ->with(102.50)
            ->willReturnSelf();

        $invoiceMock->expects($this->once())
            ->method('setBaseSubtotal')
            ->with(102.50)
            ->willReturnSelf();

        $invoiceMock->expects($this->once())
            ->method('setGrandTotal')
            ->with(112.50)
            ->willReturnSelf();

        $invoiceMock->expects($this->once())
            ->method('setBaseGrandTotal')
            ->with(112.50)
            ->willReturnSelf();

        $this->orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($orderMock);

        $this->service->applySurcharge($orderMock, $surchargeAmountCents, $invoiceMock);
    }

    /**
     * Test that zero surcharge is ignored
     */
    public function testApplySurchargeWithZeroAmount(): void
    {
        $orderMock = $this->createMock(Order::class);

        // Order should not be modified
        $orderMock->expects($this->never())->method('setData');
        $orderMock->expects($this->never())->method('setSubtotal');
        $this->orderRepositoryMock->expects($this->never())->method('save');

        $this->service->applySurcharge($orderMock, 0);
    }

    /**
     * Test that negative surcharge is ignored
     */
    public function testApplySurchargeWithNegativeAmount(): void
    {
        $orderMock = $this->createMock(Order::class);

        // Order should not be modified
        $orderMock->expects($this->never())->method('setData');
        $orderMock->expects($this->never())->method('setSubtotal');
        $this->orderRepositoryMock->expects($this->never())->method('save');

        $this->service->applySurcharge($orderMock, -100);
    }

    /**
     * Test that non-numeric surcharge is ignored
     * Note: PHP 8+ strict types will throw TypeError before reaching the method logic
     */
    public function testApplySurchargeWithInvalidAmount(): void
    {
        $orderMock = $this->createMock(Order::class);

        // Expect TypeError due to strict type declaration
        $this->expectException(\TypeError::class);

        $this->service->applySurcharge($orderMock, 'invalid');
    }

    /**
     * Test cents to currency conversion with rounding
     * UAT test case: $1.99 surcharge (199 cents) should round correctly
     */
    public function testApplySurchargeRounding(): void
    {
        $surchargeAmountCents = 199; // $1.99
        $expectedSurcharge = 1.99;

        $orderMock = $this->createConfiguredMock(Order::class, [
            'getSubtotal' => 50.00,
            'getBaseSubtotal' => 50.00,
            'getGrandTotal' => 55.00,
            'getBaseGrandTotal' => 55.00
        ]);

        $orderMock->method('setData')->willReturnSelf();
        $orderMock->method('setSubtotal')->willReturnSelf();
        $orderMock->method('setBaseSubtotal')->willReturnSelf();
        $orderMock->method('setGrandTotal')->willReturnSelf();
        $orderMock->method('setBaseGrandTotal')->willReturnSelf();
        $orderMock->method('addCommentToStatusHistory')->willReturnSelf();

        $this->orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($orderMock);

        $this->service->applySurcharge($orderMock, $surchargeAmountCents);
    }

    /**
     * Test updateOrderRefundData with credit memo
     * Regression test for ACH refund issue (SCRM-24096)
     */
    public function testUpdateOrderRefundDataWithCreditMemo(): void
    {
        $originalAmount = 100.00;
        $adjustedAmount = 103.00; // Includes $3 surcharge adjustment
        $adjustment = 3.00;

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTotalRefunded', 'getBaseTotalRefunded', 'setTotalRefunded', 'setBaseTotalRefunded'])
            ->getMock();
        
        $orderMock->method('getTotalRefunded')->willReturn(50.00);
        $orderMock->method('getBaseTotalRefunded')->willReturn(50.00);

        $creditMemoMock = $this->createMock(Creditmemo::class);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder', 'getCreditmemo'])
            ->getMock();
        
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentMock->method('getCreditmemo')->willReturn($creditMemoMock);

        $creditMemoMock->expects($this->once())
            ->method('setGrandTotal')
            ->with($adjustedAmount)
            ->willReturnSelf();

        $creditMemoMock->expects($this->once())
            ->method('setBaseGrandTotal')
            ->with($adjustedAmount)
            ->willReturnSelf();

        $creditMemoMock->expects($this->once())
            ->method('setAdjustmentPositive')
            ->with($adjustment)
            ->willReturnSelf();

        $orderMock->expects($this->once())
            ->method('setTotalRefunded')
            ->with(53.00) // 50.00 + 3.00
            ->willReturnSelf();

        $orderMock->expects($this->once())
            ->method('setBaseTotalRefunded')
            ->with(53.00)
            ->willReturnSelf();

        $this->orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($orderMock);

        $this->service->updateOrderRefundData($paymentMock, $adjustedAmount, $originalAmount);
    }

    /**
     * Test updateOrderRefundData without credit memo (no-op)
     */
    public function testUpdateOrderRefundDataWithoutCreditMemo(): void
    {
        $originalAmount = 100.00;
        $adjustedAmount = 103.00;

        $orderMock = $this->createMock(Order::class);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder', 'getCreditmemo'])
            ->getMock();
            
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentMock->method('getCreditmemo')->willReturn(null);

        // Should not attempt to save order without credit memo
        $this->orderRepositoryMock->expects($this->never())->method('save');

        $this->service->updateOrderRefundData($paymentMock, $adjustedAmount, $originalAmount);
    }

    /**
     * Test ACH refund surcharge adjustment
     * Regression test: ACH refunds were incorrectly using CC product ID
     * This test verifies the refund data update handles the surcharge correctly
     */
    public function testUpdateOrderRefundDataAchSurchargeScenario(): void
    {
        // ACH transaction with surcharge
        $baseAmount = 50.00;
        $surchargeAmount = 1.50;
        $totalWithSurcharge = 51.50;

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTotalRefunded', 'getBaseTotalRefunded', 'setTotalRefunded', 'setBaseTotalRefunded'])
            ->getMock();
            
        $orderMock->method('getTotalRefunded')->willReturn(0.00);
        $orderMock->method('getBaseTotalRefunded')->willReturn(0.00);

        $creditMemoMock = $this->createMock(Creditmemo::class);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder', 'getCreditmemo'])
            ->getMock();
            
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentMock->method('getCreditmemo')->willReturn($creditMemoMock);

        $creditMemoMock->method('setGrandTotal')->willReturnSelf();
        $creditMemoMock->method('setBaseGrandTotal')->willReturnSelf();
        $creditMemoMock->method('setAdjustmentPositive')
            ->with($surchargeAmount)
            ->willReturnSelf();

        $orderMock->method('setTotalRefunded')->willReturnSelf();
        $orderMock->method('setBaseTotalRefunded')->willReturnSelf();

        $this->orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($orderMock);

        $this->service->updateOrderRefundData($paymentMock, $totalWithSurcharge, $baseAmount);
    }

    /**
     * Test partial refund with surcharge adjustment
     * UAT test case: Partial refund should adjust amounts correctly
     */
    public function testUpdateOrderRefundDataPartialRefund(): void
    {
        $originalPartialAmount = 30.00;
        $adjustedPartialAmount = 31.20; // With surcharge
        $adjustment = 1.20;

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTotalRefunded', 'getBaseTotalRefunded', 'setTotalRefunded', 'setBaseTotalRefunded'])
            ->getMock();
            
        $orderMock->method('getTotalRefunded')->willReturn(20.00);
        $orderMock->method('getBaseTotalRefunded')->willReturn(20.00);

        $creditMemoMock = $this->createMock(Creditmemo::class);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder', 'getCreditmemo'])
            ->getMock();
            
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentMock->method('getCreditmemo')->willReturn($creditMemoMock);

        $creditMemoMock->method('setGrandTotal')->willReturnSelf();
        $creditMemoMock->method('setBaseGrandTotal')->willReturnSelf();
        $creditMemoMock->method('setAdjustmentPositive')->willReturnSelf();

        $orderMock->expects($this->once())
            ->method('setTotalRefunded')
            ->with(21.20) // 20.00 + 1.20
            ->willReturnSelf();

        $orderMock->expects($this->once())
            ->method('setBaseTotalRefunded')
            ->with(21.20)
            ->willReturnSelf();

        $this->orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($orderMock);

        $this->service->updateOrderRefundData($paymentMock, $adjustedPartialAmount, $originalPartialAmount);
    }

    /**
     * Test full refund with surcharge
     * UAT test case: Full refund should include surcharge amount
     */
    public function testUpdateOrderRefundDataFullRefund(): void
    {
        $orderTotal = 100.00;
        $orderTotalWithSurcharge = 103.00;
        $surchargeAdjustment = 3.00;

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTotalRefunded', 'getBaseTotalRefunded', 'setTotalRefunded', 'setBaseTotalRefunded'])
            ->getMock();
            
        $orderMock->method('getTotalRefunded')->willReturn(0.00);
        $orderMock->method('getBaseTotalRefunded')->willReturn(0.00);

        $creditMemoMock = $this->createMock(Creditmemo::class);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder', 'getCreditmemo'])
            ->getMock();
            
        $paymentMock->method('getOrder')->willReturn($orderMock);
        $paymentMock->method('getCreditmemo')->willReturn($creditMemoMock);

        $creditMemoMock->method('setGrandTotal')->with($orderTotalWithSurcharge)->willReturnSelf();
        $creditMemoMock->method('setBaseGrandTotal')->with($orderTotalWithSurcharge)->willReturnSelf();
        $creditMemoMock->method('setAdjustmentPositive')->with($surchargeAdjustment)->willReturnSelf();

        $orderMock->method('setTotalRefunded')->willReturnSelf();
        $orderMock->method('setBaseTotalRefunded')->willReturnSelf();

        $this->orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($orderMock);

        $this->service->updateOrderRefundData($paymentMock, $orderTotalWithSurcharge, $orderTotal);
    }
}
