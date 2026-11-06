<?php
/**
 * Unit tests for FortisApi HTTP client
 * Tests request building, retry/termination logic, error response parsing and exception messages
 */

namespace Fortispay\Fortis\Test\Unit\Model;

use Exception;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Url\DecoderInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FortisApiTest extends TestCase
{
    private const USER_ID      = 'test-user-id';
    private const USER_API_KEY = 'test-api-key';

    /**
     * @var ClientInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $httpClientMock;

    /**
     * @var Config|\PHPUnit\Framework\MockObject\Stub
     */
    private $configStub;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->configStub = $this->createStub(Config::class);
        $this->configStub->method('userId')->willReturn(self::USER_ID);
        $this->configStub->method('userApiKey')->willReturn(self::USER_API_KEY);
        $this->configStub->method('achLocationId')->willReturn('test-location-id');
    }

    /**
     * Build the client under test for the given environment
     *
     * @param string $environment
     * @return FortisApi
     */
    private function createFortisApi(string $environment = 'sandbox'): FortisApi
    {
        $this->configStub->method('environment')->willReturn($environment);
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getBaseUrl')->willReturn('https://shop.example.com/');

        return new FortisApi(
            $this->configStub,
            $this->createStub(DecoderInterface::class),
            $this->httpClientMock,
            $urlBuilder,
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * Configure a POST response and the exact number of attempts expected
     *
     * @param int $status
     * @param string $body
     * @param int $expectedAttempts
     * @return void
     */
    private function mockPostResponse(int $status, string $body, int $expectedAttempts = 1): void
    {
        $this->httpClientMock->expects($this->exactly($expectedAttempts))->method('post');
        $this->httpClientMock->method('getStatus')->willReturn($status);
        $this->httpClientMock->method('getBody')->willReturn($body);
    }

    /**
     * Run a call that is expected to fail and return the thrown exception
     *
     * @param callable $call
     * @return LocalizedException
     */
    private function captureLocalizedException(callable $call): LocalizedException
    {
        try {
            $call();
        } catch (LocalizedException $e) {
            return $e;
        }

        $this->fail('Expected ' . LocalizedException::class . ' was not thrown');
    }

    /**
     * Test sandbox environment targets the sandbox URL with the sandbox developer ID
     */
    public function testSandboxEnvironmentUsesSandboxUrlAndDeveloperId(): void
    {
        $this->httpClientMock
            ->expects($this->once())
            ->method('setHeaders')
            ->with([
                'Content-Type' => 'application/json',
                'user-id'      => self::USER_ID,
                'user-api-key' => self::USER_API_KEY,
                'developer-id' => FortisApi::DEVELOPER_ID_SANDBOX,
            ]);
        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with(FortisApi::FORTIS_API_SANDBOX . '/v1/transactions/cc/sale/ticket', '{"amount":1000}');
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn('{"type":"Transaction"}');

        $this->createFortisApi('sandbox')->ccSaleTicket(['amount' => 1000], self::USER_ID, self::USER_API_KEY);
    }

    /**
     * Test production environment targets the production URL with the production developer ID
     */
    public function testProductionEnvironmentUsesProductionUrlAndDeveloperId(): void
    {
        $this->httpClientMock
            ->expects($this->once())
            ->method('setHeaders')
            ->with($this->callback(function ($headers) {
                return $headers['developer-id'] === FortisApi::DEVELOPER_ID;
            }));
        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with(FortisApi::FORTIS_API . '/v1/transactions/cc/sale/ticket', $this->anything());
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn('{"type":"Transaction"}');

        $this->createFortisApi('production')->ccSaleTicket(['amount' => 1000], self::USER_ID, self::USER_API_KEY);
    }

    /**
     * Test any 2xx status returns the raw response body after a single attempt
     *
     * @param int $status
     */
    #[DataProvider('successStatusProvider')]
    public function testSuccessStatusReturnsRawBody(int $status): void
    {
        $body = '{"type":"Transaction","data":{"id":"31f194dd71b775b09ed6b4b2"}}';
        $this->mockPostResponse($status, $body);

        $result = $this->createFortisApi()->ccSaleTicket(['amount' => 1000], self::USER_ID, self::USER_API_KEY);

        $this->assertSame($body, $result);
    }

    /**
     * @return array
     */
    public static function successStatusProvider(): array
    {
        return [
            '200 OK'      => [200],
            '201 Created' => [201],
            '299 edge'    => [299],
        ];
    }

    /**
     * Test GET requests use get() and decode the transaction body
     */
    public function testGetTransactionUsesGetVerbAndDecodesBody(): void
    {
        $transactionId = '31f194dd71b775b09ed6b4b2';

        $this->httpClientMock->expects($this->never())->method('post');
        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with(
                FortisApi::FORTIS_API_SANDBOX
                . "/v1/transactions/{$transactionId}?expand=surcharge,account_vault"
            );
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn('{"data":{"transaction_amount":10000}}');

        $result = $this->createFortisApi()->getTransaction($transactionId, self::USER_ID, self::USER_API_KEY);

        $this->assertSame(10000, $result->data->transaction_amount);
    }

    /**
     * Test transactionId is used in the endpoint but stripped from the request payload
     */
    public function testTransactionIdIsStrippedFromPayloadAndCustomVerbIsSet(): void
    {
        $options = [];
        $this->httpClientMock
            ->method('setOption')
            ->willReturnCallback(function ($key, $value) use (&$options) {
                $options[$key] = $value;
            });
        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with(
                FortisApi::FORTIS_API_SANDBOX . '/v1/transactions/31f194dd71b775b09ed6b4b2/refund',
                '{"transaction_amount":500}'
            );
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn('{}');

        $this->createFortisApi()->refundTransactionAmount(
            ['transactionId' => '31f194dd71b775b09ed6b4b2', 'transaction_amount' => 500],
            self::USER_ID,
            self::USER_API_KEY
        );

        $this->assertSame('PATCH', $options[CURLOPT_CUSTOMREQUEST]);
    }

    /**
     * Test each Fortis error response shape is parsed into the exception message
     *
     * @param string $body
     * @param string $expectedMessage
     */
    #[DataProvider('errorResponseShapeProvider')]
    public function testErrorResponseShapesAreParsedIntoExceptionMessage(string $body, string $expectedMessage): void
    {
        $this->mockPostResponse(422, $body);
        $api = $this->createFortisApi();

        $exception = $this->captureLocalizedException(
            fn() => $api->ccSaleTicket(['amount' => 1000], self::USER_ID, self::USER_API_KEY)
        );

        $this->assertSame($expectedMessage, $exception->getMessage());
    }

    /**
     * @return array
     */
    public static function errorResponseShapeProvider(): array
    {
        return [
            'meta.errors (field => messages)' => [
                '{"type":"Error","meta":{"errors":{"transaction_amount":["Transaction amount is required"],'
                . '"zip":["Zip is invalid","Zip is too long"]}}}',
                "Transaction amount is required\nZip is invalid\n",
            ],
            'meta.details (list of message objects)' => [
                '{"type":"Error","meta":{"details":[{"message":"Card expired"},{"message":"CVV mismatch"}]}}',
                "Card expired\nCVV mismatch\n",
            ],
            'meta.message' => [
                '{"type":"Error","meta":{"message":"Product transaction not active"}}',
                'Product transaction not active',
            ],
            'detail' => [
                '{"type":"Error","detail":"Transaction not found"}',
                'Transaction not found',
            ],
            'meta.errors takes precedence over meta.message' => [
                '{"type":"Error","meta":{"message":"Validation failed","errors":{"zip":["Zip is invalid"]}}}',
                "Zip is invalid\n",
            ],
        ];
    }

    /**
     * Test a non-error-shaped body falls back to the generic failure message
     *
     * Note: the message always says "after 5 attempts", even when a 4xx stopped after one attempt.
     *
     * @param string $body
     * @param string $expectedMessage
     */
    #[DataProvider('unrecognisedErrorBodyProvider')]
    public function testUnrecognisedErrorBodyFallsBackToGenericMessage(string $body, string $expectedMessage): void
    {
        $this->mockPostResponse(400, $body);
        $api = $this->createFortisApi();

        $exception = $this->captureLocalizedException(
            fn() => $api->ccSaleTicket(['amount' => 1000], self::USER_ID, self::USER_API_KEY)
        );

        $this->assertSame($expectedMessage, $exception->getMessage());
    }

    /**
     * @return array
     */
    public static function unrecognisedErrorBodyProvider(): array
    {
        return [
            'JSON without type Error is echoed back' => [
                '{"message":"Bad request"}',
                'API Request failed after 5 attempts. {"message":"Bad request"}',
            ],
            'non-JSON body loses the underlying detail' => [
                '<html>Bad Request</html>',
                'API Request failed after 5 attempts. null',
            ],
        ];
    }

    /**
     * Test 4xx responses terminate immediately without retrying
     *
     * @param int $status
     */
    #[DataProvider('clientErrorStatusProvider')]
    public function testClientErrorStatusIsNotRetried(int $status): void
    {
        $this->mockPostResponse($status, '{"type":"Error","detail":"Client error"}', 1);
        $api = $this->createFortisApi();

        $exception = $this->captureLocalizedException(
            fn() => $api->ccSaleTicket(['amount' => 1000], self::USER_ID, self::USER_API_KEY)
        );

        $this->assertSame('Client error', $exception->getMessage());
    }

    /**
     * @return array
     */
    public static function clientErrorStatusProvider(): array
    {
        return [
            '400 Bad Request'          => [400],
            '401 Unauthorized'         => [401],
            '404 Not Found'            => [404],
            '412 Precondition Failed'  => [412],
            '422 Unprocessable Entity' => [422],
            '499 edge'                 => [499],
        ];
    }

    /**
     * Test 5xx responses are attempted exactly 5 times before the error is thrown
     *
     * @param int $status
     */
    #[DataProvider('serverErrorStatusProvider')]
    public function testServerErrorStatusIsRetriedFiveTimesThenThrows(int $status): void
    {
        $this->mockPostResponse($status, '{"type":"Error","detail":"Service unavailable"}', 5);
        $api = $this->createFortisApi();

        $exception = $this->captureLocalizedException(
            fn() => $api->ccSaleTicket(['amount' => 1000], self::USER_ID, self::USER_API_KEY)
        );

        $this->assertSame('Service unavailable', $exception->getMessage());
    }

    /**
     * @return array
     */
    public static function serverErrorStatusProvider(): array
    {
        return [
            '500 Internal Server Error' => [500],
            '502 Bad Gateway'           => [502],
            '503 Service Unavailable'   => [503],
            '504 Gateway Timeout'       => [504],
        ];
    }

    /**
     * Test a transient 5xx followed by a 2xx returns the successful body
     */
    public function testServerErrorThenSuccessReturnsBody(): void
    {
        $this->httpClientMock->expects($this->exactly(3))->method('post');
        $this->httpClientMock->method('getStatus')->willReturnOnConsecutiveCalls(503, 502, 200);
        $this->httpClientMock->method('getBody')->willReturnOnConsecutiveCalls(
            '{"type":"Error","detail":"Service unavailable"}',
            '{"type":"Error","detail":"Bad gateway"}',
            '{"type":"Transaction"}'
        );

        $result = $this->createFortisApi()->ccSaleTicket(['amount' => 1000], self::USER_ID, self::USER_API_KEY);

        $this->assertSame('{"type":"Transaction"}', $result);
    }

    /**
     * Test transport exceptions are retried 5 times then wrapped with the original message
     */
    public function testTransportExceptionIsRetriedFiveTimesThenWrapped(): void
    {
        $this->httpClientMock
            ->expects($this->exactly(5))
            ->method('post')
            ->willThrowException(new Exception('Connection timed out after 30001 milliseconds'));
        $this->httpClientMock->expects($this->never())->method('getStatus');
        $api = $this->createFortisApi();

        $exception = $this->captureLocalizedException(
            fn() => $api->ccSaleTicket(['amount' => 1000], self::USER_ID, self::USER_API_KEY)
        );

        $this->assertSame(
            'API Request failed: Connection timed out after 30001 milliseconds',
            $exception->getMessage()
        );
    }

    /**
     * Test a transient transport exception followed by success returns the body
     */
    public function testTransportExceptionThenSuccessReturnsBody(): void
    {
        $attempts = 0;
        $this->httpClientMock
            ->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function () use (&$attempts) {
                if (++$attempts < 3) {
                    throw new Exception('Could not resolve host');
                }
            });
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn('{"type":"Transaction"}');

        $result = $this->createFortisApi()->ccSaleTicket(['amount' => 1000], self::USER_ID, self::USER_API_KEY);

        $this->assertSame('{"type":"Transaction"}', $result);
    }

    /**
     * Test getClientToken returns the client token from a successful response
     */
    public function testGetClientTokenReturnsToken(): void
    {
        $this->mockPostResponse(200, '{"data":{"client_token":"eyJhbGciOi.payload.sig"}}');

        $result = $this->createFortisApi()->getClientToken(['action' => 'sale'], self::USER_ID, self::USER_API_KEY);

        $this->assertSame('eyJhbGciOi.payload.sig', $result);
    }

    /**
     * Test getClientToken throws when a 2xx response has no client token
     */
    public function testGetClientTokenThrowsWhenTokenMissing(): void
    {
        $this->mockPostResponse(200, '{"data":{}}');
        $api = $this->createFortisApi();

        $exception = $this->captureLocalizedException(
            fn() => $api->getClientToken(['action' => 'sale'], self::USER_ID, self::USER_API_KEY)
        );

        $this->assertSame('Invalid response from API', $exception->getMessage());
    }

    /**
     * Test currency validation turns raw API errors into actionable messages
     *
     * @param string $apiMessage
     * @param string $expectedMessage
     */
    #[DataProvider('currencyValidationErrorProvider')]
    public function testValidateProductIdCurrencyMapsApiErrorsToActionableMessages(
        string $apiMessage,
        string $expectedMessage
    ): void {
        $this->mockPostResponse(422, json_encode(['type' => 'Error', 'meta' => ['message' => $apiMessage]]));
        $api = $this->createFortisApi();

        $exception = $this->captureLocalizedException(
            fn() => $api->validateProductIdCurrency('pt-123', 'EUR')
        );

        $this->assertSame($expectedMessage, $exception->getMessage());
    }

    /**
     * @return array
     */
    public static function currencyValidationErrorProvider(): array
    {
        return [
            'currency inside methods array' => [
                'methods[0].currency is not allowed',
                'API request structure error for Product Transaction ID pt-123. The currency field should not be '
                . 'inside the methods array. This indicates a configuration issue with the Fortis API integration.',
            ],
            'product transaction ID not found' => [
                'product_transaction_id not found',
                'Product Transaction ID pt-123 was not found in your Fortis sandbox account during currency '
                . 'validation. Please verify: 1) The Product Transaction ID exists in your Fortis dashboard, '
                . '2) It is configured for credit card processing, 3) It supports currency EUR, 4) You are using '
                . 'the correct environment. Current API endpoint: https://api.sandbox.fortis.tech',
            ],
            'currency not allowed' => [
                'currency_code is not allowed',
                'Currency EUR is not supported for Product Transaction ID pt-123. Please contact Fortis to enable '
                . 'multicurrency support or verify the Product ID configuration.',
            ],
            'multi-currency not enabled (412)' => [
                'Multi-currency not enabled',
                'Multi-currency is not enabled for Product Transaction ID pt-123. Please contact Fortis to enable '
                . 'multicurrency support for currency EUR. (Error 412)',
            ],
            'missing currency (400)' => [
                '400: Missing currency',
                'Currency field is required for Product Transaction ID pt-123 when using EUR. Please ensure '
                . 'multicurrency is properly configured.',
            ],
            'unsupported card type' => [
                'Unsupported card type',
                'Some card types (e.g., Amex) may not be supported for currency EUR. Please check with Fortis '
                . 'about card type restrictions.',
            ],
            'unmapped error preserves API detail' => [
                'Gateway rejected the request',
                'Failed to validate Product ID pt-123 with currency EUR. API Error: Gateway rejected the request',
            ],
        ];
    }

    /**
     * Test USD and CAD skip the remote currency validation entirely
     *
     * @param string $currency
     */
    #[DataProvider('nativeCurrencyProvider')]
    public function testValidateProductIdCurrencySkipsApiForNativeCurrencies(string $currency): void
    {
        $this->httpClientMock->expects($this->never())->method('post');

        $this->assertTrue($this->createFortisApi()->validateProductIdCurrency('pt-123', $currency));
    }

    /**
     * @return array
     */
    public static function nativeCurrencyProvider(): array
    {
        return [
            'USD' => ['USD'],
            'CAD' => ['CAD'],
        ];
    }

    /**
     * Test the APM webhook is registered for the APM product and points at the APM controller
     */
    public function testCreateApmTransactionWebhookRegistersApmProductAgainstApmHook(): void
    {
        $this->configStub->method('apmIsActive')->willReturn(true);
        $this->configStub->method('apmProductId')->willReturn('apm-product-id');
        $this->configStub->method('achProductId')->willReturn('ach-product-id');

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with(
                FortisApi::FORTIS_API_SANDBOX . '/v1/webhooks/transaction',
                $this->callback(function (string $body): bool {
                    $payload = json_decode($body, true);

                    return $payload['url'] === 'https://shop.example.com/fortis/webhook/apmhook'
                        && $payload['product_transaction_id'] === 'apm-product-id'
                        && $payload['location_id'] === 'test-location-id'
                        && $payload['on_create'] === 1
                        && $payload['on_update'] === 1;
                })
            );
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn('{"type":"Webhook","data":{"id":"apm-webhook-id"}}');

        $this->assertSame('apm-webhook-id', $this->createFortisApi()->createApmTransactionWebhook());
    }

    /**
     * Test the ACH webhook still targets the ACH product and the ACH controller
     */
    public function testCreateTransactionWebhookRegistersAchProductAgainstAchHook(): void
    {
        $this->configStub->method('achIsActive')->willReturn(true);
        $this->configStub->method('apmProductId')->willReturn('apm-product-id');
        $this->configStub->method('achProductId')->willReturn('ach-product-id');

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with(
                FortisApi::FORTIS_API_SANDBOX . '/v1/webhooks/transaction',
                $this->callback(function (string $body): bool {
                    $payload = json_decode($body, true);

                    return $payload['url'] === 'https://shop.example.com/fortis/webhook/achhook'
                        && $payload['product_transaction_id'] === 'ach-product-id'
                        && $payload['on_create'] === 0
                        && $payload['on_update'] === 1;
                })
            );
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn('{"type":"Webhook","data":{"id":"ach-webhook-id"}}');

        $this->assertSame('ach-webhook-id', $this->createFortisApi()->createTransactionWebhook());
    }

    /**
     * Test no APM webhook is registered while APM is off or has no product ID
     *
     * @param bool $active
     * @param string $productId
     */
    #[DataProvider('apmWebhookNotRegisteredProvider')]
    public function testCreateApmTransactionWebhookIsSkipped(bool $active, string $productId): void
    {
        $this->configStub->method('apmIsActive')->willReturn($active);
        $this->configStub->method('apmProductId')->willReturn($productId);
        $this->httpClientMock->expects($this->never())->method('post');

        $this->assertNull($this->createFortisApi()->createApmTransactionWebhook());
    }

    /**
     * @return array
     */
    public static function apmWebhookNotRegisteredProvider(): array
    {
        return [
            'APM disabled'      => [false, 'apm-product-id'],
            'no APM product id' => [true, ''],
        ];
    }

    /**
     * Test a webhook delete error surfaces as a LocalizedException instead of a TypeError
     */
    public function testDeleteTransactionWebhookThrowsLocalizedExceptionOnError(): void
    {
        $this->mockPostResponse(200, '{"type":"Error","title":"Webhook not found"}');

        $exception = $this->captureLocalizedException(
            fn() => $this->createFortisApi()->deleteTransactionWebhook('webhook-1')
        );

        $this->assertSame('Webhook not found', $exception->getMessage());
    }
}
