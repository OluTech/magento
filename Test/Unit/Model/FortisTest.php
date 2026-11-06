<?php
/**
 * Unit tests for the Fortis payment method model
 * Tests capture/void/refund lifecycle, auth-complete intent building, and billing country validation
 */

namespace Fortispay\Fortis\Test\Unit\Model;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\ConfigFactory;
use Fortispay\Fortis\Model\Fortis;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Service\FortisMethodService;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Payment;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// Order/Payment partial doubles from getMockBuilder() are used as stubs
#[AllowMockObjectsWithoutExpectations]
class FortisTest extends TestCase
{
    /**
     * @var Fortis
     */
    private Fortis $fortis;

    /**
     * @var FortisMethodService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fortisMethodServiceMock;

    /**
     * @var FortisApi|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fortisApiMock;

    /**
     * @var DirectoryHelper|\PHPUnit\Framework\MockObject\Stub
     */
    private $directoryHelperStub;

    /**
     * Config values returned by the scope config stub, keyed by path
     *
     * @var array
     */
    private array $configValues = [];

    protected function setUp(): void
    {
        $this->configValues = [
            'payment/fortis/user_id'      => 'enc-user',
            'payment/fortis/user_api_key' => 'enc-key',
        ];
        $this->fortisMethodServiceMock = $this->createStub(FortisMethodService::class);
        $this->fortisApiMock = $this->createStub(FortisApi::class);
        $this->directoryHelperStub = $this->createStub(DirectoryHelper::class);

        $this->buildFortis();
    }

    /**
     * Build the model from the current doubles (call again after swapping in a mock)
     */
    private function buildFortis(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn($path) => $this->configValues[$path] ?? null
        );

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(fn($value) => 'decrypted-' . $value);

        $configFactory = $this->createStub(ConfigFactory::class);
        $configFactory->method('create')->willReturn($this->createStub(Config::class));

        $this->fortis = new Fortis(
            $this->createStub(ManagerInterface::class),
            $scopeConfig,
            $configFactory,
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(UrlInterface::class),
            $encryptor,
            $this->createStub(OrderRepositoryInterface::class),
            $this->fortisMethodServiceMock,
            $this->directoryHelperStub,
            $this->fortisApiMock
        );
    }

    /**
     * Create an order double with the given status and totals
     */
    private function createOrder(string $status = Order::STATE_PROCESSING, array $totals = []): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getStatus',
                'getId',
                'getSubtotal',
                'getShippingAmount',
                'getDiscountAmount',
                'getOrderCurrencyCode',
                'getBillingAddress',
            ])
            ->getMock();
        $order->method('getStatus')->willReturn($status);
        $order->method('getId')->willReturn(1773);
        $order->method('getSubtotal')->willReturn($totals['subtotal'] ?? 90.00);
        $order->method('getShippingAmount')->willReturn($totals['shipping'] ?? 10.00);
        $order->method('getDiscountAmount')->willReturn($totals['discount'] ?? -5.00);
        $order->method('getOrderCurrencyCode')->willReturn('USD');

        return $order;
    }

    /**
     * Create an order payment double for the given order and raw transaction details
     */
    private function createPayment(Order $order, ?array $rawDetails = null): Payment
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder', 'getAdditionalInformation'])
            ->getMock();
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')->willReturn(
            $rawDetails === null ? [] : ['raw_details_info' => json_encode($rawDetails)]
        );

        $this->fortis->setInfoInstance($payment);

        return $payment;
    }

    /**
     * Raw details of an approved card auth-only transaction
     */
    private function authOnlyDetails(array $overrides = []): array
    {
        return array_merge([
            'id'             => '31f194dd71b775b09ed6b4b2',
            'payment_method' => 'cc',
            'auth_amount'    => 9800, // $98.00 in cents
            'tax'            => 300,  // $3.00 in cents
        ], $overrides);
    }

    /**
     * Swap in a FortisApi mock expecting a single auth-complete call and capture its intent data
     */
    private function expectAuthComplete(?array &$capturedIntent): void
    {
        $this->fortisApiMock = $this->createMock(FortisApi::class);
        $this->fortisApiMock->expects($this->once())
            ->method('doCompleteAuthTransaction')
            ->with(
                $this->callback(function ($intentData) use (&$capturedIntent) {
                    $capturedIntent = $intentData;
                    return true;
                }),
                'decrypted-enc-user',
                'decrypted-enc-key'
            );
        $this->buildFortis();
    }

    /**
     * Swap in a FortisApi mock that must never complete an auth
     */
    private function expectNoAuthComplete(): void
    {
        $this->fortisApiMock = $this->createMock(FortisApi::class);
        $this->fortisApiMock->expects($this->never())->method('doCompleteAuthTransaction');
        $this->buildFortis();
    }

    /**
     * Configure the country restriction settings
     */
    private function restrictCountries(mixed $allowSpecific, string $countries): void
    {
        $this->configValues['payment/fortis/allowspecific'] = $allowSpecific;
        $this->configValues['payment/fortis/specificcountry'] = $countries;
        $this->buildFortis();
    }

    /**
     * Create an order payment whose order has the given billing country
     */
    private function createPaymentWithBillingCountry(?string $countryId): Payment
    {
        $address = $this->getMockBuilder(OrderAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCountryId'])
            ->getMock();
        $address->method('getCountryId')->willReturn($countryId);

        $order = $this->createOrder();
        $order->method('getBillingAddress')->willReturn($address);

        return $this->createPayment($order);
    }

    /**
     * Test capture is unavailable while the order awaits payment
     */
    public function testCanCaptureFalseWhenPendingPayment(): void
    {
        $this->createPayment($this->createOrder(Order::STATE_PENDING_PAYMENT));

        $this->assertFalse($this->fortis->canCapture());
    }

    /**
     * Test capture is available once the order is processing
     */
    public function testCanCaptureTrueWhenProcessing(): void
    {
        $this->createPayment($this->createOrder(Order::STATE_PROCESSING));

        $this->assertTrue($this->fortis->canCapture());
    }

    /**
     * Test capture throws when the order is still pending payment
     */
    public function testCaptureThrowsWhenCannotCapture(): void
    {
        $this->expectNoAuthComplete();
        $payment = $this->createPayment(
            $this->createOrder(Order::STATE_PENDING_PAYMENT),
            $this->authOnlyDetails()
        );

        try {
            $this->fortis->capture($payment, 98.00);
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertSame('The capture action is not available.', $e->getMessage());
        }
    }

    /**
     * Test capture is a no-op when no raw transaction details are stored
     */
    public function testCaptureSkipsWhenNoRawDetails(): void
    {
        $this->expectNoAuthComplete();
        $payment = $this->createPayment($this->createOrder());

        $this->assertSame($this->fortis, $this->fortis->capture($payment, 98.00));
    }

    /**
     * Test capture is a no-op for ACH transactions
     */
    public function testCaptureSkipsAchTransaction(): void
    {
        $this->expectNoAuthComplete();
        $payment = $this->createPayment($this->createOrder(), $this->authOnlyDetails(['payment_method' => 'ach']));

        $this->assertSame($this->fortis, $this->fortis->capture($payment, 98.00));
    }

    /**
     * Test capture is a no-op for sale transactions that have no auth amount
     */
    public function testCaptureSkipsWhenNoAuthAmount(): void
    {
        $this->expectNoAuthComplete();
        $details = $this->authOnlyDetails();
        unset($details['auth_amount']);
        $payment = $this->createPayment($this->createOrder(), $details);

        $this->assertSame($this->fortis, $this->fortis->capture($payment, 98.00));
    }

    /**
     * Test capture builds the auth-complete intent and applies the order currency
     */
    public function testCaptureBuildsAuthCompleteIntent(): void
    {
        $expectedIntent = [
            'transaction_amount' => 9800, // $98.00 in cents
            'order_number'       => 1773,
            'transactionId'      => '31f194dd71b775b09ed6b4b2',
            'tax'                => 300,  // $3.00 in cents
            'subtotal_amount'    => 9500, // $90.00 + $10.00 shipping - $5.00 discount, in cents
        ];

        $this->fortisMethodServiceMock = $this->createMock(FortisMethodService::class);
        $this->fortisMethodServiceMock->expects($this->once())
            ->method('applySecondaryCurrency')
            ->with($expectedIntent, 'USD');
        $this->expectAuthComplete($capturedIntent);
        $payment = $this->createPayment($this->createOrder(), $this->authOnlyDetails());

        $this->assertSame($this->fortis, $this->fortis->capture($payment, 98.00));
        $this->assertSame($expectedIntent, $capturedIntent);
    }

    /**
     * Test a nested surcharge object is split out of the subtotal
     */
    public function testCaptureSubtractsNestedSurcharge(): void
    {
        $this->expectAuthComplete($capturedIntent);
        $payment = $this->createPayment($this->createOrder(), $this->authOnlyDetails([
            'surcharge'        => ['surcharge_amount' => 285], // $2.85 in cents
            'surcharge_amount' => 999, // ignored when the nested value is present
        ]));

        $this->fortis->capture($payment, 98.00);

        $this->assertSame(9215, $capturedIntent['subtotal_amount']); // $95.00 - $2.85 in cents
        $this->assertSame(285, $capturedIntent['surcharge_amount']);
    }

    /**
     * Test a flat surcharge amount is split out of the subtotal
     */
    public function testCaptureSubtractsFlatSurcharge(): void
    {
        $this->expectAuthComplete($capturedIntent);
        $payment = $this->createPayment(
            $this->createOrder(),
            $this->authOnlyDetails(['surcharge_amount' => 150]) // $1.50 in cents
        );

        $this->fortis->capture($payment, 98.00);

        $this->assertSame(9350, $capturedIntent['subtotal_amount']); // $95.00 - $1.50 in cents
        $this->assertSame(150, $capturedIntent['surcharge_amount']);
    }

    /**
     * Test a zero flat surcharge leaves the subtotal untouched
     */
    public function testCaptureIgnoresZeroFlatSurcharge(): void
    {
        $this->expectAuthComplete($capturedIntent);
        $payment = $this->createPayment($this->createOrder(), $this->authOnlyDetails(['surcharge_amount' => 0]));

        $this->fortis->capture($payment, 98.00);

        $this->assertSame(9500, $capturedIntent['subtotal_amount']); // $95.00 in cents
        $this->assertArrayNotHasKey('surcharge_amount', $capturedIntent);
    }

    /**
     * Test void is always available
     */
    public function testCanVoidReturnsTrue(): void
    {
        $this->assertTrue($this->fortis->canVoid());
    }

    /**
     * Test void delegates to the method service
     */
    public function testVoidDelegatesToVoidOnline(): void
    {
        $payment = $this->createStub(Payment::class);
        $this->fortisMethodServiceMock = $this->createMock(FortisMethodService::class);
        $this->fortisMethodServiceMock->expects($this->once())->method('voidOnline')->with($payment);
        $this->buildFortis();

        $this->assertSame($this->fortis, $this->fortis->void($payment));
    }

    /**
     * Test cancel voids the payment through the method service
     */
    public function testCancelDelegatesToVoidOnline(): void
    {
        $payment = $this->createStub(Payment::class);
        $this->fortisMethodServiceMock = $this->createMock(FortisMethodService::class);
        $this->fortisMethodServiceMock->expects($this->once())->method('voidOnline')->with($payment);
        $this->buildFortis();

        $this->assertSame($this->fortis, $this->fortis->cancel($payment));
    }

    /**
     * Test refund passes the payment and amount to the method service
     */
    public function testRefundDelegatesPaymentAndAmount(): void
    {
        $payment = $this->createStub(Payment::class);
        $this->fortisMethodServiceMock = $this->createMock(FortisMethodService::class);
        $this->fortisMethodServiceMock->expects($this->once())
            ->method('refundOnline')
            ->with($payment, 25.50)
            ->willReturn(true);
        $this->buildFortis();

        $this->assertSame($this->fortis, $this->fortis->refund($payment, 25.50));
    }

    /**
     * Test validation reads the billing country from the order for order payments
     */
    public function testValidateUsesOrderBillingCountryForOrderPayment(): void
    {
        $this->restrictCountries(1, 'US,CA');
        $this->directoryHelperStub->method('getDefaultCountry')->willReturn('GB');
        $this->createPaymentWithBillingCountry('US');

        $this->assertSame($this->fortis, $this->fortis->validate());
    }

    /**
     * Test validation reads the billing country from the quote for quote payments
     */
    public function testValidateUsesQuoteBillingCountryForQuotePayment(): void
    {
        $this->restrictCountries(1, 'US,CA');
        $this->directoryHelperStub->method('getDefaultCountry')->willReturn('GB');

        $address = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCountryId'])
            ->getMock();
        $address->method('getCountryId')->willReturn('CA');
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBillingAddress'])
            ->getMock();
        $quote->method('getBillingAddress')->willReturn($address);
        $payment = $this->getMockBuilder(QuotePayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $payment->method('getQuote')->willReturn($quote);
        $this->fortis->setInfoInstance($payment);

        $this->assertSame($this->fortis, $this->fortis->validate());
    }

    /**
     * Test validation falls back to the store default country when billing has none
     */
    public function testValidateFallsBackToDefaultCountry(): void
    {
        $this->restrictCountries(1, 'CA');
        $this->directoryHelperStub->method('getDefaultCountry')->willReturn('CA');
        $this->createPaymentWithBillingCountry(null);

        $this->assertSame($this->fortis, $this->fortis->validate());
    }

    /**
     * Test validation throws when the billing country is not in the allowed list
     */
    public function testValidateThrowsForRestrictedCountry(): void
    {
        $this->restrictCountries(1, 'US,CA');
        $this->createPaymentWithBillingCountry('GB');

        try {
            $this->fortis->validate();
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertSame(
                'You can\'t use the payment type you selected to make payments to the billing country.',
                $e->getMessage()
            );
        }
    }

    /**
     * Test any country is allowed when specific countries are not enforced
     */
    public function testValidateAllowsAnyCountryWhenNotRestricted(): void
    {
        $this->restrictCountries(0, 'US,CA');
        $this->createPaymentWithBillingCountry('GB');

        $this->assertSame($this->fortis, $this->fortis->validate());
    }

    /**
     * Test the string "1" from scope config does not enforce the restriction (strict === 1 check)
     */
    public function testValidateIgnoresStringAllowSpecificFlag(): void
    {
        // Pins current behaviour: ScopeConfig returns "1", which never matches === 1
        $this->restrictCountries('1', 'US,CA');
        $this->createPaymentWithBillingCountry('GB');

        $this->assertSame($this->fortis, $this->fortis->validate());
    }
}
