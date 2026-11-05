<?php
/**
 * Unit tests for Cart model
 * Tests calculation overrides and amount validation
 */

namespace Fortispay\Fortis\Test\Unit\Model;

use Fortispay\Fortis\Model\Cart;
use Magento\Payment\Model\Cart\SalesModel\SalesModelInterface;
use PHPUnit\Framework\TestCase;

class CartTest extends TestCase
{
    /**
     * @var Cart
     */
    private Cart $cart;

    /**
     * @var SalesModelInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $salesModelMock;

    /**
     * @var \Magento\Payment\Model\Cart\SalesModel\Factory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $salesModelFactoryMock;

    /**
     * @var \Magento\Framework\Event\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eventManagerMock;

    protected function setUp(): void
    {
        $this->salesModelMock = $this->createMock(SalesModelInterface::class);
        $this->salesModelFactoryMock = $this->createMock(\Magento\Payment\Model\Cart\SalesModel\Factory::class);
        $this->salesModelFactoryMock->method('create')->willReturn($this->salesModelMock);
        $this->eventManagerMock = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
    }

    /**
     * Helper to create a mock Order Item
     */
    private function createOrderItemMock(
        $price,
        $qty,
        $name = 'Test Product',
        $baseRowTotal = null
    ) {
        $baseRowTotal = $baseRowTotal ?? ($price * $qty);
        
        $itemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentItem', 'getName', 'getPrice'])
            ->getMock();
        
        $itemMock->method('getParentItem')->willReturn(null);
        $itemMock->method('getName')->willReturn($name);
        $itemMock->method('getPrice')->willReturn($price);
        $itemMock->setData('qty', $qty);
        
        $originalItemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseRowTotal'])
            ->getMock();
        $originalItemMock->method('getBaseRowTotal')->willReturn($baseRowTotal);
        
        $itemMock->setData('original_item', $originalItemMock);
        
        return $itemMock;
    }

    /**
     * Helper to create tax container object
     */
    private function createTaxContainer($discountTaxComp = 0.00, $shippingDiscountTaxComp = 0.00)
    {
        return new class($discountTaxComp, $shippingDiscountTaxComp) {
            /**
             * @var float
             */
            private $discountTax;
            
            /**
             * @var float
             */
            private $shippingDiscountTax;
            
            public function __construct($discountTax, $shippingDiscountTax)
            {
                $this->discountTax = $discountTax;
                $this->shippingDiscountTax = $shippingDiscountTax;
            }
            
            public function getBaseDiscountTaxCompensationAmount()
            {
                return $this->discountTax;
            }
            
            public function getBaseShippingDiscountTaxCompensationAmnt()
            {
                return $this->shippingDiscountTax;
            }
        };
    }

    /**
     * Test getAmounts returns combined subtotal when amounts are invalid
     */
    public function testGetAmountsWithInvalidAmounts(): void
    {
        $this->salesModelMock->method('getDataUsingMethod')
            ->with('base_grand_total')
            ->willReturn(100.00);

        $this->salesModelMock->method('getAllItems')
            ->willReturn([]);

        $this->salesModelMock->method('getBaseSubtotal')
            ->willReturn(80.00);

        $this->salesModelMock->method('getBaseTaxAmount')
            ->willReturn(8.00);

        $this->salesModelMock->method('getBaseShippingAmount')
            ->willReturn(10.00);

        $this->salesModelMock->method('getBaseDiscountAmount')
            ->willReturn(0.00);

        $taxContainer = $this->createTaxContainer(0.00, 0.00);
        $this->salesModelMock->method('getTaxContainer')
            ->willReturn($taxContainer);

        $this->cart = new Cart($this->salesModelFactoryMock, $this->eventManagerMock, $this->salesModelMock);

        $amounts = $this->cart->getAmounts();

        // Should return simplified subtotal when amounts are invalid
        $this->assertArrayHasKey(Cart::AMOUNT_SUBTOTAL, $amounts);
        $this->assertEquals(98.00, $amounts[Cart::AMOUNT_SUBTOTAL]); // 80 + 8 + 10
    }

    /**
     * Test hasNegativeItemAmount returns true when items have negative amounts
     */
    public function testHasNegativeItemAmountWithNegativeItems(): void
    {
        $this->salesModelMock->method('getDataUsingMethod')->willReturn(100.00);
        $this->salesModelMock->method('getAllItems')->willReturn([]);
        $this->salesModelMock->method('getBaseSubtotal')->willReturn(80.00);
        $this->salesModelMock->method('getBaseTaxAmount')->willReturn(8.00);
        $this->salesModelMock->method('getBaseShippingAmount')->willReturn(10.00);
        $this->salesModelMock->method('getBaseDiscountAmount')->willReturn(0.00);

        $taxContainer = $this->createTaxContainer(0.00, 0.00);
        $this->salesModelMock->method('getTaxContainer')->willReturn($taxContainer);

        $this->cart = new Cart($this->salesModelFactoryMock, $this->eventManagerMock, $this->salesModelMock);

        // Add a custom item with negative amount
        $this->cart->addCustomItem('Negative Item', 1, -10.00);

        $this->assertTrue($this->cart->hasNegativeItemAmount());
    }

    /**
     * Test hasNegativeItemAmount returns false when all items are positive
     */
    public function testHasNegativeItemAmountWithPositiveItems(): void
    {
        $this->salesModelMock->method('getDataUsingMethod')->willReturn(100.00);
        $this->salesModelMock->method('getAllItems')->willReturn([]);
        $this->salesModelMock->method('getBaseSubtotal')->willReturn(80.00);
        $this->salesModelMock->method('getBaseTaxAmount')->willReturn(8.00);
        $this->salesModelMock->method('getBaseShippingAmount')->willReturn(10.00);
        $this->salesModelMock->method('getBaseDiscountAmount')->willReturn(0.00);

        $taxContainer = $this->createTaxContainer(0.00, 0.00);
        $this->salesModelMock->method('getTaxContainer')->willReturn($taxContainer);

        $this->cart = new Cart($this->salesModelFactoryMock, $this->eventManagerMock, $this->salesModelMock);

        // Add only positive items
        $this->cart->addCustomItem('Positive Item', 2, 10.00);

        $this->assertFalse($this->cart->hasNegativeItemAmount());
    }

    /**
     * Test discount validation: discount should be less than subtotal
     */
    public function testValidateAmountsWithDiscountGreaterThanSubtotal(): void
    {
        // Create a sales model item
        $itemMock = $this->createOrderItemMock(50.00, 1, 'Test Product', 50.00);

        $this->salesModelMock->method('getAllItems')->willReturn([$itemMock]);
        $this->salesModelMock->method('getBaseSubtotal')->willReturn(50.00);
        $this->salesModelMock->method('getBaseTaxAmount')->willReturn(5.00);
        $this->salesModelMock->method('getBaseShippingAmount')->willReturn(10.00);
        $this->salesModelMock->method('getBaseDiscountAmount')->willReturn(60.00); // Discount > subtotal
        $this->salesModelMock->method('getDataUsingMethod')
            ->with('base_grand_total')
            ->willReturn(5.00); // 50 + 5 + 10 - 60

        $taxContainer = $this->createTaxContainer(0.00, 0.00);
        $this->salesModelMock->method('getTaxContainer')->willReturn($taxContainer);

        $this->cart = new Cart($this->salesModelFactoryMock, $this->eventManagerMock, $this->salesModelMock);

        // When discount > subtotal, amounts should be invalid and items cleared
        $amounts = $this->cart->getAmounts();
        $items = $this->cart->getAllItems();

        // Items should be cleared due to invalid amounts
        $this->assertEmpty($items);
    }

    /**
     * Test amount validation with precision matching
     * Tests the sprintf('%.4F') comparison logic
     */
    public function testValidateAmountsWithPrecisionMatching(): void
    {
        $itemMock = $this->createOrderItemMock(33.33, 3, 'Test Product', 99.99);

        $this->salesModelMock->method('getAllItems')->willReturn([$itemMock]);
        $this->salesModelMock->method('getBaseSubtotal')->willReturn(99.99);
        $this->salesModelMock->method('getBaseTaxAmount')->willReturn(10.00);
        $this->salesModelMock->method('getBaseShippingAmount')->willReturn(5.00);
        $this->salesModelMock->method('getBaseDiscountAmount')->willReturn(5.00);
        $this->salesModelMock->method('getDataUsingMethod')
            ->with('base_grand_total')
            ->willReturn(109.99); // Should match with precision

        $taxContainer = $this->createTaxContainer(0.00, 0.00);
        $this->salesModelMock->method('getTaxContainer')->willReturn($taxContainer);

        $this->cart = new Cart($this->salesModelFactoryMock, $this->eventManagerMock, $this->salesModelMock);

        $items = $this->cart->getAllItems();

        // Items should be present when amounts validate correctly
        $this->assertNotEmpty($items);
    }

    /**
     * Test aggregated item label when item price doesn't match precision
     * Workaround for item subtotal precision issues
     */
    public function testImportItemsWithPrecisionWorkaround(): void
    {
        $itemMock = $this->createOrderItemMock(10.995, 2, 'Test Product', 21.99);

        $this->salesModelMock->method('getAllItems')->willReturn([$itemMock]);
        $this->salesModelMock->method('getBaseSubtotal')->willReturn(21.99);
        $this->salesModelMock->method('getBaseTaxAmount')->willReturn(2.20);
        $this->salesModelMock->method('getBaseShippingAmount')->willReturn(5.00);
        $this->salesModelMock->method('getBaseDiscountAmount')->willReturn(0.00);
        $this->salesModelMock->method('getDataUsingMethod')
            ->with('base_grand_total')
            ->willReturn(29.19);

        $taxContainer = $this->createTaxContainer(0.00, 0.00);
        $this->salesModelMock->method('getTaxContainer')->willReturn($taxContainer);

        $this->cart = new Cart($this->salesModelFactoryMock, $this->eventManagerMock, $this->salesModelMock);

        $items = $this->cart->getAllItems();

        // Should aggregate the item with qty in the label
        $this->assertNotEmpty($items);
        // Item name should include " x2" due to precision workaround
        $firstItem = reset($items);
        $this->assertStringContainsString('x2', $firstItem->getName());
    }

    /**
     * Test aggregated item label when qty * price doesn't match row total
     * Another workaround scenario
     */
    public function testImportItemsWithRowTotalMismatch(): void
    {
        $itemMock = $this->createOrderItemMock(10.00, 3, 'Test Product', 29.99); // Not 30.00

        $this->salesModelMock->method('getAllItems')->willReturn([$itemMock]);
        $this->salesModelMock->method('getBaseSubtotal')->willReturn(29.99);
        $this->salesModelMock->method('getBaseTaxAmount')->willReturn(3.00);
        $this->salesModelMock->method('getBaseShippingAmount')->willReturn(5.00);
        $this->salesModelMock->method('getBaseDiscountAmount')->willReturn(0.00);
        $this->salesModelMock->method('getDataUsingMethod')
            ->with('base_grand_total')
            ->willReturn(37.99);

        $taxContainer = $this->createTaxContainer(0.00, 0.00);
        $this->salesModelMock->method('getTaxContainer')->willReturn($taxContainer);

        $this->cart = new Cart($this->salesModelFactoryMock, $this->eventManagerMock, $this->salesModelMock);

        $items = $this->cart->getAllItems();

        // Should aggregate when qty * price doesn't match row total
        $this->assertNotEmpty($items);
        $firstItem = reset($items);
        $this->assertStringContainsString('x3', $firstItem->getName());
    }

    /**
     * Test discount tax compensation workaround
     * Handles hidden discount tax and shipping discount tax
     */
    public function testDiscountTaxCompensationWorkaround(): void
    {
        $itemMock = $this->createOrderItemMock(100.00, 1, 'Test Product', 100.00);

        $this->salesModelMock->method('getAllItems')->willReturn([$itemMock]);
        $this->salesModelMock->method('getBaseSubtotal')->willReturn(100.00);
        $this->salesModelMock->method('getBaseTaxAmount')->willReturn(10.00);
        $this->salesModelMock->method('getBaseShippingAmount')->willReturn(15.00);
        $this->salesModelMock->method('getBaseDiscountAmount')->willReturn(10.00);
        $this->salesModelMock->method('getDataUsingMethod')
            ->with('base_grand_total')
            ->willReturn(117.50); // Includes tax compensation

        $taxContainer = $this->createTaxContainer(2.00, 0.50);
        $this->salesModelMock->method('getTaxContainer')->willReturn($taxContainer);

        $this->cart = new Cart($this->salesModelFactoryMock, $this->eventManagerMock, $this->salesModelMock);

        // Trigger collection to apply tax compensation workaround
        $this->cart->getAllItems();
        
        // Tax should include compensation amounts
        $tax = $this->cart->getTax();
        
        // Base tax (10.00) + discount compensation (2.00) + shipping discount compensation (0.50) = 12.50
        $this->assertEquals(12.50, $tax);
    }

    /**
     * Test that parent items are skipped during import
     */
    public function testImportItemsSkipsParentItems(): void
    {
        $parentItemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentItem'])
            ->getMock();
        
        // Parent items have a truthy parent (themselves or another item)
        $parentItemMock->method('getParentItem')->willReturn($parentItemMock);
        
        $childItemMock = $this->createOrderItemMock(50.00, 1, 'Child Product', 50.00);

        // Return both parent and child, but only child should be imported
        $this->salesModelMock->method('getAllItems')->willReturn([$parentItemMock, $childItemMock]);
        $this->salesModelMock->method('getBaseSubtotal')->willReturn(50.00);
        $this->salesModelMock->method('getBaseTaxAmount')->willReturn(5.00);
        $this->salesModelMock->method('getBaseShippingAmount')->willReturn(5.00);
        $this->salesModelMock->method('getBaseDiscountAmount')->willReturn(0.00);
        $this->salesModelMock->method('getDataUsingMethod')
            ->with('base_grand_total')
            ->willReturn(60.00);

        $taxContainer = $this->createTaxContainer(0.00, 0.00);
        $this->salesModelMock->method('getTaxContainer')->willReturn($taxContainer);

        $this->cart = new Cart($this->salesModelFactoryMock, $this->eventManagerMock, $this->salesModelMock);
        $items = $this->cart->getAllItems();
        
        // Should only have the child item (parent is skipped)
        $this->assertCount(1, $items);
    }
}
