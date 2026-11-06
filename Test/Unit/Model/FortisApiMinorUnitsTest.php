<?php
/**
 * Unit tests for currency minor-unit handling in FortisApi
 * Tests the currency-aware test amount and documents where inline bcmul(x, 100) conversions
 * diverge from ISO 4217 currency exponents
 */

namespace Fortispay\Fortis\Test\Unit\Model;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Directory\Model\Country;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Url\DecoderInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class FortisApiMinorUnitsTest extends TestCase
{
    /**
     * @var FortisApi
     */
    private FortisApi $fortisApi;

    /**
     * @var ClientInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $httpClientMock;

    /**
     * Request body sent on the last POST
     *
     * @var string|null
     */
    private ?string $postedBody = null;

    protected function setUp(): void
    {
        $configStub = $this->createStub(Config::class);
        $configStub->method('environment')->willReturn('sandbox');
        $configStub->method('userId')->willReturn('test-user-id');
        $configStub->method('userApiKey')->willReturn('test-api-key');
        $configStub->method('achLocationId')->willReturn('test-location-id');

        $this->httpClientMock = $this->createMock(ClientInterface::class);

        $this->fortisApi = new FortisApi(
            $configStub,
            $this->createStub(DecoderInterface::class),
            $this->httpClientMock,
            $this->createStub(UrlInterface::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * Capture the POST body and answer with a successful response
     *
     * @param string $responseBody
     * @return void
     */
    private function expectSinglePost(string $responseBody): void
    {
        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->willReturnCallback(function ($uri, $params) {
                $this->postedBody = $params;
            });
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn($responseBody);
    }

    /**
     * Test the validation test amount for 2-, 0- and 3-decimal currencies
     *
     * Zero-decimal currencies currently get 100 (i.e. 100 major units), the same value as the
     * 2-decimal default, so the zero-decimal list in production has no effect on the result.
     *
     * @param string $currency
     * @param int $expectedMinorUnits
     */
    #[DataProvider('minorUnitsTestAmountProvider')]
    public function testGetMinorUnitsTestAmount(string $currency, int $expectedMinorUnits): void
    {
        $this->httpClientMock->expects($this->never())->method('post');
        $method = new ReflectionMethod(FortisApi::class, 'getMinorUnitsTestAmount');

        $this->assertSame($expectedMinorUnits, $method->invoke($this->fortisApi, $currency));
    }

    /**
     * @return array
     */
    public static function minorUnitsTestAmountProvider(): array
    {
        return [
            'USD (2 decimals) => 1.00'  => ['USD', 100],
            'EUR (2 decimals) => 1.00'  => ['EUR', 100],
            'JPY (0 decimals) => 100'   => ['JPY', 100],
            'KRW (0 decimals) => 100'   => ['KRW', 100],
            'CLP (0 decimals) => 100'   => ['CLP', 100],
            'KWD (3 decimals) => 1.000' => ['KWD', 1000],
        ];
    }

    /**
     * Test currency validation sends the currency-specific test amount in the intention payload
     *
     * @param string $currency
     * @param int $expectedMinorUnits
     */
    #[DataProvider('validatedCurrencyProvider')]
    public function testValidateProductIdCurrencySendsMinorUnitTestAmount(
        string $currency,
        int $expectedMinorUnits
    ): void {
        $this->expectSinglePost('{"data":{"client_token":"token"}}');

        $this->assertTrue($this->fortisApi->validateProductIdCurrency('pt-123', $currency));

        $payload = json_decode($this->postedBody, true);
        $this->assertSame($expectedMinorUnits, $payload['amount']);
        $this->assertSame($currency, $payload['currency_code']);
    }

    /**
     * @return array
     */
    public static function validatedCurrencyProvider(): array
    {
        return [
            'EUR' => ['EUR', 100],
            'JPY' => ['JPY', 100],
            'KWD' => ['KWD', 1000],
        ];
    }

    /**
     * Test Level 3 amounts are converted with a fixed x100 multiplier, truncating extra decimals
     *
     * @param string $currency
     * @param string $majorAmount
     * @param int $expectedMinorUnits
     */
    #[DataProvider('level3ConversionProvider')]
    public function testLevel3ConversionUsesFixedTwoDecimalMultiplier(
        string $currency,
        string $majorAmount,
        int $expectedMinorUnits
    ): void {
        $this->assertSame(
            $expectedMinorUnits,
            $this->sendLevel3FreightAmount($majorAmount),
            "$currency $majorAmount"
        );
    }

    /**
     * @return array
     */
    public static function level3ConversionProvider(): array
    {
        return [
            'USD 15.99'                      => ['USD', '15.99', 1599],
            'EUR 0.01'                       => ['EUR', '0.01', 1],
            'USD 19.999 truncates, no round' => ['USD', '19.999', 1999],
            'JPY 1500'                       => ['JPY', '1500', 150000],
            'KWD 1.250'                      => ['KWD', '1.250', 125],
        ];
    }

    /**
     * Test documents that Level 3 amounts ignore the currency exponent for non-2-decimal currencies
     *
     * Every inline bcmul((string)$amount, '100', 0) in the module behaves this way. If minor-unit
     * conversion is made currency-aware, update these rows to the ISO 4217 values.
     *
     * @param string $currency
     * @param string $majorAmount
     * @param int $isoMinorUnits
     */
    #[DataProvider('isoDivergenceProvider')]
    public function testLevel3ConversionDivergesFromIsoExponent(
        string $currency,
        string $majorAmount,
        int $isoMinorUnits
    ): void {
        $sent = $this->sendLevel3FreightAmount($majorAmount);

        $this->assertSame((int)bcmul($majorAmount, '100', 0), $sent);
        $this->assertNotSame($isoMinorUnits, $sent, "$currency is sent with a 2-decimal multiplier");
    }

    /**
     * @return array
     */
    public static function isoDivergenceProvider(): array
    {
        return [
            'JPY exponent 0: 1500 should be 1500'   => ['JPY', '1500', 1500],
            'KRW exponent 0: 25000 should be 25000' => ['KRW', '25000', 25000],
            'CLP exponent 0: 9990 should be 9990'   => ['CLP', '9990', 9990],
            'KWD exponent 3: 1.250 should be 1250'  => ['KWD', '1.250', 1250],
        ];
    }

    /**
     * Send a Visa Level 3 entry with the given shipping amount and return the posted freight_amount
     *
     * @param string $shippingAmount
     * @return int
     */
    private function sendLevel3FreightAmount(string $shippingAmount): int
    {
        $this->expectSinglePost('{"type":"Level3"}');

        $address = $this->createStub(Address::class);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getPostcode')->willReturn('10001');

        $order = $this->createStub(Order::class);
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getCreatedAt')->willReturn('2026-10-06 12:00:00');
        $order->method('getShippingAmount')->willReturn($shippingAmount);
        $order->method('getTaxAmount')->willReturn('0');
        $order->method('getAllItems')->willReturn([]);

        $store = $this->createStub(Store::class);
        $store->method('getConfig')->willReturn('90210');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $country = $this->createStub(Country::class);
        $country->method('loadByCode')->willReturnSelf();
        $country->method('getData')->willReturn('840');
        $countryFactory = $this->createStub(CountryFactory::class);
        $countryFactory->method('create')->willReturn($country);

        $this->fortisApi->createVisaLevel3Entry($order, $storeManager, $countryFactory, '31f194dd71b775b09ed6b4b2');

        return json_decode($this->postedBody, true)['level3_data']['freight_amount'];
    }
}
