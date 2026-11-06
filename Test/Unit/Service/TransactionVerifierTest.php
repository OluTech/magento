<?php
/**
 * Unit tests for TransactionVerifier service
 * Tests the 3-way surcharge fallback logic and transaction verification
 */

namespace Fortispay\Fortis\Test\Unit\Service;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Service\TransactionVerifier;
use PHPUnit\Framework\TestCase;

class TransactionVerifierTest extends TestCase
{
    /**
     * @var TransactionVerifier
     */
    private TransactionVerifier $transactionVerifier;

    /**
     * @var FortisApi|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fortisApiMock;

    /**
     * @var Config|\PHPUnit\Framework\MockObject\MockObject
     */
    private $configMock;

    protected function setUp(): void
    {
        $this->fortisApiMock = $this->createMock(FortisApi::class);
        $this->configMock = $this->createMock(Config::class);

        $this->transactionVerifier = new TransactionVerifier(
            $this->fortisApiMock,
            $this->configMock
        );
    }

    /**
     * Test successful transaction verification with no surcharge
     */
    public function testVerifyTransactionByIdWithoutSurcharge(): void
    {
        $transactionId = '31f194dd71b775b09ed6b4b2';
        $orderId = '1773';
        $checkoutTotal = 10000; // $100.00 in cents

        $apiResponse = (object)[
            'data' => [
                'id' => $transactionId,
                'transaction_amount' => 10000,
                'description' => $orderId,
                'status' => 'approved'
            ]
        ];

        $this->configMock->method('userId')->willReturn('test-user-id');
        $this->configMock->method('userApiKey')->willReturn('test-api-key');

        $this->fortisApiMock
            ->expects($this->once())
            ->method('getTransaction')
            ->with($transactionId, 'test-user-id', 'test-api-key')
            ->willReturn($apiResponse);

        $result = $this->transactionVerifier->verifyTransactionById(
            $transactionId,
            $orderId,
            $checkoutTotal
        );

        $this->assertTrue($result['verified']);
        $this->assertEmpty($result['errors']);
        $this->assertNotNull($result['transaction']);
    }

    /**
     * Test 3-way surcharge fallback: Tier 1 - surcharge_amount at root level
     */
    public function testVerifyTransactionWithSurchargeTier1RootLevel(): void
    {
        $transactionId = '31f194e8aa61d062a5513737';
        $orderId = '1782';
        $checkoutTotal = 10000; // $100.00 in cents
        $surchargeAmount = 300; // $3.00 in cents

        $apiResponse = (object)[
            'data' => [
                'id' => $transactionId,
                'transaction_amount' => 10300, // Total with surcharge
                'surcharge_amount' => $surchargeAmount,
                'description' => $orderId,
                'status' => 'approved'
            ]
        ];

        $this->configMock->method('userId')->willReturn('test-user-id');
        $this->configMock->method('userApiKey')->willReturn('test-api-key');

        $this->fortisApiMock
            ->method('getTransaction')
            ->willReturn($apiResponse);

        $result = $this->transactionVerifier->verifyTransactionById(
            $transactionId,
            $orderId,
            $checkoutTotal
        );

        $this->assertTrue($result['verified']);
        $this->assertEmpty($result['errors']);
        $this->assertEquals(10300, $result['transaction']['data']['transaction_amount']);
    }

    /**
     * Test 3-way surcharge fallback: Tier 2 - nested surcharge object
     */
    public function testVerifyTransactionWithSurchargeTier2NestedObject(): void
    {
        $transactionId = '31f194e8aa61d062a5513737';
        $orderId = '1782';
        $checkoutTotal = 10000;
        $surchargeAmount = 300;

        $apiResponse = (object)[
            'data' => [
                'id' => $transactionId,
                'transaction_amount' => 10300,
                'surcharge' => [
                    'surcharge_amount' => $surchargeAmount
                ],
                'description' => $orderId,
                'status' => 'approved'
            ]
        ];

        $this->configMock->method('userId')->willReturn('test-user-id');
        $this->configMock->method('userApiKey')->willReturn('test-api-key');

        $this->fortisApiMock
            ->method('getTransaction')
            ->willReturn($apiResponse);

        $result = $this->transactionVerifier->verifyTransactionById(
            $transactionId,
            $orderId,
            $checkoutTotal
        );

        $this->assertTrue($result['verified']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test 3-way surcharge fallback: Tier 3 - client-supplied surcharge info
     */
    public function testVerifyTransactionWithSurchargeTier3ClientSupplied(): void
    {
        $transactionId = '31f194e8aa61d062a5513737';
        $orderId = '1782';
        $checkoutTotal = 10000;
        $surchargeInfo = ['surchargeAmount' => 300];

        // API response has no surcharge data
        $apiResponse = (object)[
            'data' => [
                'id' => $transactionId,
                'transaction_amount' => 10300,
                'description' => $orderId,
                'status' => 'approved'
            ]
        ];

        $this->configMock->method('userId')->willReturn('test-user-id');
        $this->configMock->method('userApiKey')->willReturn('test-api-key');

        $this->fortisApiMock
            ->method('getTransaction')
            ->willReturn($apiResponse);

        $result = $this->transactionVerifier->verifyTransactionById(
            $transactionId,
            $orderId,
            $checkoutTotal,
            $surchargeInfo
        );

        $this->assertTrue($result['verified']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test transaction verification failure when API fetch fails
     */
    public function testVerifyTransactionByIdWhenApiFetchFails(): void
    {
        $transactionId = 'invalid-transaction-id';
        $orderId = '9999';
        $checkoutTotal = 10000;

        $this->configMock->method('userId')->willReturn('test-user-id');
        $this->configMock->method('userApiKey')->willReturn('test-api-key');

        // API returns invalid response (empty StdClass)
        $invalidResponse = new \StdClass();
        $this->fortisApiMock
            ->method('getTransaction')
            ->willReturn($invalidResponse);

        $result = $this->transactionVerifier->verifyTransactionById(
            $transactionId,
            $orderId,
            $checkoutTotal
        );

        $this->assertFalse($result['verified']);
        $this->assertNotEmpty($result['errors']);
        $this->assertEquals('fetch_failed', $result['errors'][0]['type']);
        $this->assertNull($result['transaction']);
    }

    /**
     * Test transaction verification with description mismatch
     */
    public function testVerifyTransactionWithDescriptionMismatch(): void
    {
        $transactionId = '31f194dd71b775b09ed6b4b2';
        $expectedOrderId = '1773';
        $actualOrderId = '9999'; // Wrong order ID
        $checkoutTotal = 10000;

        $apiResponse = (object)[
            'data' => [
                'id' => $transactionId,
                'transaction_amount' => 10000,
                'description' => $actualOrderId,
                'status' => 'approved'
            ]
        ];

        $this->configMock->method('userId')->willReturn('test-user-id');
        $this->configMock->method('userApiKey')->willReturn('test-api-key');

        $this->fortisApiMock
            ->method('getTransaction')
            ->willReturn($apiResponse);

        $result = $this->transactionVerifier->verifyTransactionById(
            $transactionId,
            $expectedOrderId,
            $checkoutTotal
        );

        $this->assertFalse($result['verified']);
        $this->assertNotEmpty($result['errors']);
        $this->assertEquals('description_mismatch', $result['errors'][0]['type']);
        $this->assertEquals($expectedOrderId, $result['errors'][0]['expected']);
        $this->assertEquals($actualOrderId, $result['errors'][0]['actual']);
    }

    /**
     * Test transaction verification with amount mismatch
     */
    public function testVerifyTransactionWithAmountMismatch(): void
    {
        $transactionId = '31f194dd71b775b09ed6b4b2';
        $orderId = '1773';
        $checkoutTotal = 10000;
        $actualAmount = 9500; // Wrong amount

        $apiResponse = (object)[
            'data' => [
                'id' => $transactionId,
                'transaction_amount' => $actualAmount,
                'description' => $orderId,
                'status' => 'approved'
            ]
        ];

        $this->configMock->method('userId')->willReturn('test-user-id');
        $this->configMock->method('userApiKey')->willReturn('test-api-key');

        $this->fortisApiMock
            ->method('getTransaction')
            ->willReturn($apiResponse);

        $result = $this->transactionVerifier->verifyTransactionById(
            $transactionId,
            $orderId,
            $checkoutTotal
        );

        $this->assertFalse($result['verified']);
        $this->assertNotEmpty($result['errors']);
        $this->assertEquals('amount_mismatch', $result['errors'][0]['type']);
        $this->assertEquals($checkoutTotal, $result['errors'][0]['expected']);
        $this->assertEquals($actualAmount, $result['errors'][0]['actual']);
        $this->assertEquals($actualAmount - $checkoutTotal, $result['errors'][0]['difference']);
    }

    /**
     * Test APM (PayPal) transaction with missing description is still valid
     * Per the code comment: "Description may be absent for APM (PayPal) transactions"
     */
    public function testVerifyTransactionApmWithMissingDescription(): void
    {
        $transactionId = 'apm-paypal-transaction';
        $orderId = '2000';
        $checkoutTotal = 5000;

        $apiResponse = (object)[
            'data' => [
                'id' => $transactionId,
                'transaction_amount' => 5000,
                // No description field for APM transaction
                'status' => 'approved'
            ]
        ];

        $this->configMock->method('userId')->willReturn('test-user-id');
        $this->configMock->method('userApiKey')->willReturn('test-api-key');

        $this->fortisApiMock
            ->method('getTransaction')
            ->willReturn($apiResponse);

        $result = $this->transactionVerifier->verifyTransactionById(
            $transactionId,
            $orderId,
            $checkoutTotal
        );

        // Should pass verification since description is not required for APM
        $this->assertTrue($result['verified']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test isTransactionVerifiedById convenience method
     */
    public function testIsTransactionVerifiedById(): void
    {
        $transactionId = '31f194dd71b775b09ed6b4b2';
        $orderId = '1773';
        $checkoutTotal = 10000;

        $apiResponse = (object)[
            'data' => [
                'id' => $transactionId,
                'transaction_amount' => 10000,
                'description' => $orderId,
                'status' => 'approved'
            ]
        ];

        $this->configMock->method('userId')->willReturn('test-user-id');
        $this->configMock->method('userApiKey')->willReturn('test-api-key');

        $this->fortisApiMock
            ->method('getTransaction')
            ->willReturn($apiResponse);

        $result = $this->transactionVerifier->isTransactionVerifiedById(
            $transactionId,
            $orderId,
            $checkoutTotal
        );

        $this->assertTrue($result);
    }

    /**
     * Test multiple errors (both description and amount mismatch)
     */
    public function testVerifyTransactionWithMultipleErrors(): void
    {
        $transactionId = '31f194dd71b775b09ed6b4b2';
        $expectedOrderId = '1773';
        $actualOrderId = '9999';
        $checkoutTotal = 10000;
        $actualAmount = 9500;

        $apiResponse = (object)[
            'data' => [
                'id' => $transactionId,
                'transaction_amount' => $actualAmount,
                'description' => $actualOrderId,
                'status' => 'approved'
            ]
        ];

        $this->configMock->method('userId')->willReturn('test-user-id');
        $this->configMock->method('userApiKey')->willReturn('test-api-key');

        $this->fortisApiMock
            ->method('getTransaction')
            ->willReturn($apiResponse);

        $result = $this->transactionVerifier->verifyTransactionById(
            $transactionId,
            $expectedOrderId,
            $checkoutTotal
        );

        $this->assertFalse($result['verified']);
        $this->assertCount(2, $result['errors']);
        $this->assertEquals('description_mismatch', $result['errors'][0]['type']);
        $this->assertEquals('amount_mismatch', $result['errors'][1]['type']);
    }

    /**
     * Test the transaction data is returned as an array when Fortis finds the transaction
     */
    public function testFetchTransactionDataReturnsTheDataArray(): void
    {
        $this->configMock->expects($this->once())->method('userId')->willReturn('test-user-id');
        $this->configMock->expects($this->once())->method('userApiKey')->willReturn('test-api-key');
        $this->fortisApiMock
            ->expects($this->once())
            ->method('getTransaction')
            ->with('31f194dd71b775b09ed6b4b2', 'test-user-id', 'test-api-key')
            ->willReturn((object)['data' => (object)['id' => '31f194dd71b775b09ed6b4b2', 'transaction_amount' => 100]]);

        $this->assertSame(
            ['id' => '31f194dd71b775b09ed6b4b2', 'transaction_amount' => 100],
            $this->transactionVerifier->fetchTransactionData('31f194dd71b775b09ed6b4b2')
        );
    }

    /**
     * Test null is returned when Fortis returns no data member
     */
    public function testFetchTransactionDataReturnsNullWithoutData(): void
    {
        $this->configMock->expects($this->once())->method('userId')->willReturn('test-user-id');
        $this->configMock->expects($this->once())->method('userApiKey')->willReturn('test-api-key');
        $this->fortisApiMock
            ->expects($this->once())
            ->method('getTransaction')
            ->willReturn((object)['type' => 'Error']);

        $this->assertNull($this->transactionVerifier->fetchTransactionData('missing'));
    }
}
