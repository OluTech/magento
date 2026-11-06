<?php
/**
 * Unit tests for Config model
 * Tests ACH-vs-CC product ID selection, multi-currency logic, and method activation
 */

namespace Fortispay\Fortis\Test\Unit\Model;

use Fortispay\Fortis\Model\Config;
use Magento\Directory\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigTest extends TestCase
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfigMock;

    /**
     * @var Data|\PHPUnit\Framework\MockObject\MockObject
     */
    private $directoryHelperMock;

    /**
     * @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManagerMock;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    /**
     * @var Repository|\PHPUnit\Framework\MockObject\MockObject
     */
    private $assetRepoMock;

    /**
     * @var EncryptorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $encryptorMock;

    /**
     * @var WriterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $configWriterMock;

    /**
     * @var Store|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeMock;

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->directoryHelperMock = $this->createMock(Data::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->assetRepoMock = $this->createMock(Repository::class);
        $this->encryptorMock = $this->createMock(EncryptorInterface::class);
        $this->configWriterMock = $this->createMock(WriterInterface::class);
        
        // getStoreId() is a magic DataObject getter, so it is backed by store_id data
        $this->storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrentCurrency'])
            ->getMock();

        $this->storeMock->setData('store_id', 1);
        $this->storeManagerMock->method('getStore')->willReturn($this->storeMock);

        $this->config = new Config(
            $this->scopeConfigMock,
            $this->directoryHelperMock,
            $this->storeManagerMock,
            $this->loggerMock,
            $this->assetRepoMock,
            $this->encryptorMock,
            $this->configWriterMock
        );
    }

    /**
     * Helper to set config value expectation
     */
    private function setConfigValue(string $field, $value): void
    {
        $path = 'payment/' . Config::METHOD_CODE . '/' . $field;
        $this->scopeConfigMock
            ->method('getValue')
            ->with($path, ScopeInterface::SCOPE_STORE)
            ->willReturn($value);
    }

    /**
     * Test ccProductId returns CC product ID when ACH is NOT active
     * UAT test case: CC Sale transactions
     */
    public function testCcProductIdWhenAchNotActive(): void
    {
        $encryptedProductId = 'encrypted_cc_product_id';
        $decryptedProductId = '11f194fd87653a80a56135f7';

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '0'],
                ['payment/fortis/product_transaction_id', ScopeInterface::SCOPE_STORE, null, $encryptedProductId]
            ]);

        $this->encryptorMock
            ->method('decrypt')
            ->with($encryptedProductId)
            ->willReturn($decryptedProductId);

        $result = $this->config->ccProductId();

        $this->assertEquals($decryptedProductId, $result);
    }

    /**
     * Test ccProductId returns ACH CC product ID when ACH IS active
     * UAT test case: ACH Debit transactions
     * Critical for ACH-vs-CC product ID selection
     */
    public function testCcProductIdWhenAchIsActive(): void
    {
        $encryptedAchCcProductId = 'encrypted_ach_cc_product_id';
        $decryptedAchCcProductId = '11f195b01c14e43e8be25944';

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '1'],
                ['payment/fortis/fortis_ach_cc_product_id', ScopeInterface::SCOPE_STORE, null, $encryptedAchCcProductId]
            ]);

        $this->encryptorMock
            ->method('decrypt')
            ->with($encryptedAchCcProductId)
            ->willReturn($decryptedAchCcProductId);

        $result = $this->config->ccProductId();

        $this->assertEquals($decryptedAchCcProductId, $result);
    }

    /**
     * Test ccProductId returns empty string when no product ID configured
     */
    public function testCcProductIdWhenNotConfigured(): void
    {
        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '0'],
                ['payment/fortis/product_transaction_id', ScopeInterface::SCOPE_STORE, null, '']
            ]);

        $result = $this->config->ccProductId();

        $this->assertEquals('', $result);
    }

    /**
     * Test getProductIdForCurrency returns primary product ID when multi-currency disabled
     */
    public function testGetProductIdForCurrencyWhenMultiCurrencyDisabled(): void
    {
        $primaryProductId = '11f194fd87653a80a56135f7';

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/multi_currency_enabled', ScopeInterface::SCOPE_STORE, null, '0'],
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '0'],
                ['payment/fortis/product_transaction_id', ScopeInterface::SCOPE_STORE, null, $primaryProductId]
            ]);

        $this->encryptorMock->method('decrypt')->willReturn($primaryProductId);

        $result = $this->config->getProductIdForCurrency('EUR');

        $this->assertEquals($primaryProductId, $result);
    }

    /**
     * Test getProductIdForCurrency returns primary product ID for primary currency
     * UAT test case: Payment with USD (primary currency)
     */
    public function testGetProductIdForCurrencyPrimaryCurrency(): void
    {
        $primaryCurrency = 'USD';
        $primaryProductId = '11f194fd87653a80a56135f7';

        $currencyMock = $this->createMock(\Magento\Directory\Model\Currency::class);
        $currencyMock->method('getCode')->willReturn($primaryCurrency);
        $this->storeMock->method('getCurrentCurrency')->willReturn($currencyMock);

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/multi_currency_enabled', ScopeInterface::SCOPE_STORE, null, '1'],
                ['payment/fortis/product_currency_primary', ScopeInterface::SCOPE_STORE, null, $primaryCurrency],
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '0'],
                ['payment/fortis/product_transaction_id', ScopeInterface::SCOPE_STORE, null, $primaryProductId]
            ]);

        $this->encryptorMock->method('decrypt')->willReturn($primaryProductId);

        $result = $this->config->getProductIdForCurrency($primaryCurrency);

        $this->assertEquals($primaryProductId, $result);
    }

    /**
     * Test getProductIdForCurrency returns secondary product ID for secondary currency
     * UAT test case: Payment with INR (secondary currency)
     * Critical multi-currency branch logic test
     */
    public function testGetProductIdForCurrencySecondaryCurrency(): void
    {
        $primaryCurrency = 'USD';
        $secondaryCurrency = 'INR';
        $primaryProductId = '11f194fd87653a80a56135f7';
        $secondaryProductId = '11f195ba4218acf69d0bad8a';

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/multi_currency_enabled', ScopeInterface::SCOPE_STORE, null, '1'],
                [
                    'payment/fortis/product_currency_primary',
                    ScopeInterface::SCOPE_STORE,
                    null,
                    $primaryCurrency
                ],
                [
                    'payment/fortis/product_currency_secondary',
                    ScopeInterface::SCOPE_STORE,
                    null,
                    $secondaryCurrency
                ],
                [
                    'payment/fortis/product_transaction_id_secondary',
                    ScopeInterface::SCOPE_STORE,
                    null,
                    $secondaryProductId
                ],
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '0'],
                [
                    'payment/fortis/product_transaction_id',
                    ScopeInterface::SCOPE_STORE,
                    null,
                    $primaryProductId
                ]
            ]);

        $this->encryptorMock
            ->method('decrypt')
            ->willReturnMap([
                [$primaryProductId, $primaryProductId],
                [$secondaryProductId, $secondaryProductId]
            ]);

        $result = $this->config->getProductIdForCurrency($secondaryCurrency);

        $this->assertEquals($secondaryProductId, $result);
    }

    /**
     * Test getProductIdForCurrency falls back to primary when secondary not configured
     */
    public function testGetProductIdForCurrencySecondaryNotConfigured(): void
    {
        $primaryCurrency = 'USD';
        $secondaryCurrency = 'EUR';
        $primaryProductId = '11f194fd87653a80a56135f7';

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/multi_currency_enabled', ScopeInterface::SCOPE_STORE, null, '1'],
                ['payment/fortis/product_currency_primary', ScopeInterface::SCOPE_STORE, null, $primaryCurrency],
                ['payment/fortis/product_currency_secondary', ScopeInterface::SCOPE_STORE, null, $secondaryCurrency],
                ['payment/fortis/product_transaction_id_secondary', ScopeInterface::SCOPE_STORE, null, ''],
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '0'],
                ['payment/fortis/product_transaction_id', ScopeInterface::SCOPE_STORE, null, $primaryProductId]
            ]);

        $this->encryptorMock->method('decrypt')->willReturn($primaryProductId);

        $result = $this->config->getProductIdForCurrency($secondaryCurrency);

        // Should fall back to primary product ID
        $this->assertEquals($primaryProductId, $result);
    }

    /**
     * Test getProductIdForCurrency with unsupported currency falls back to primary
     */
    public function testGetProductIdForCurrencyUnsupportedCurrency(): void
    {
        $primaryCurrency = 'USD';
        $secondaryCurrency = 'INR';
        $unsupportedCurrency = 'JPY';
        $primaryProductId = '11f194fd87653a80a56135f7';

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/multi_currency_enabled', ScopeInterface::SCOPE_STORE, null, '1'],
                ['payment/fortis/product_currency_primary', ScopeInterface::SCOPE_STORE, null, $primaryCurrency],
                ['payment/fortis/product_currency_secondary', ScopeInterface::SCOPE_STORE, null, $secondaryCurrency],
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '0'],
                ['payment/fortis/product_transaction_id', ScopeInterface::SCOPE_STORE, null, $primaryProductId]
            ]);

        $this->encryptorMock->method('decrypt')->willReturn($primaryProductId);

        $result = $this->config->getProductIdForCurrency($unsupportedCurrency);

        // Should fall back to primary product ID
        $this->assertEquals($primaryProductId, $result);
    }

    /**
     * Test isMethodActive returns true when method is enabled and supported
     */
    public function testIsMethodActiveWhenEnabledAndSupported(): void
    {
        $this->scopeConfigMock
            ->method('isSetFlag')
            ->with('payment/fortis/active', ScopeInterface::SCOPE_STORE, 1)
            ->willReturn(true);

        $this->directoryHelperMock
            ->method('getDefaultCountry')
            ->with(1)
            ->willReturn('US');

        $result = $this->config->isMethodActive('fortis');

        $this->assertTrue($result);
    }

    /**
     * Test isMethodActive returns false when method is disabled
     */
    public function testIsMethodActiveWhenDisabled(): void
    {
        $this->scopeConfigMock
            ->method('isSetFlag')
            ->with('payment/fortis/active', ScopeInterface::SCOPE_STORE, 1)
            ->willReturn(false);

        $this->directoryHelperMock
            ->method('getDefaultCountry')
            ->with(1)
            ->willReturn('US');

        $result = $this->config->isMethodActive('fortis');

        $this->assertFalse($result);
    }

    /**
     * Test isMethodActive returns true even for unsupported country
     * Note: The current implementation of isMethodSupportedForCountry() always returns methods
     * via getCountryMethods() which defaults to 'other' => ['fortis'], so all countries are supported
     */
    public function testIsMethodActiveWhenNotSupportedForCountry(): void
    {
        $this->scopeConfigMock
            ->method('isSetFlag')
            ->with('payment/fortis/active', ScopeInterface::SCOPE_STORE, 1)
            ->willReturn(true);

        $this->directoryHelperMock
            ->method('getDefaultCountry')
            ->with(1)
            ->willReturn('XX'); // Any country

        $result = $this->config->isMethodActive('fortis');

        // Should return true because 'other' fallback always includes 'fortis'
        $this->assertTrue($result);
    }

    /**
     * Test achIsActive returns true when configured
     * UAT test case: ACH Debit scenarios
     */
    public function testAchIsActiveWhenEnabled(): void
    {
        $this->scopeConfigMock
            ->method('getValue')
            ->with('payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE)
            ->willReturn('1');

        $result = $this->config->achIsActive();

        $this->assertTrue($result);
    }

    /**
     * Test achIsActive returns false when not configured
     */
    public function testAchIsActiveWhenDisabled(): void
    {
        $this->scopeConfigMock
            ->method('getValue')
            ->with('payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE)
            ->willReturn('0');

        $result = $this->config->achIsActive();

        $this->assertFalse($result);
    }

    /**
     * Test isMultiCurrencyEnabled returns correct value
     */
    public function testIsMultiCurrencyEnabled(): void
    {
        $this->scopeConfigMock
            ->method('getValue')
            ->with('payment/fortis/multi_currency_enabled', ScopeInterface::SCOPE_STORE)
            ->willReturn('1');

        $result = $this->config->isMultiCurrencyEnabled();

        $this->assertTrue($result);
    }

    /**
     * Test getCurrencyProductIdMap returns correct mapping
     */
    public function testGetCurrencyProductIdMap(): void
    {
        $primaryCurrency = 'USD';
        $secondaryCurrency = 'INR';
        $primaryProductId = '11f194fd87653a80a56135f7';
        $secondaryProductId = '11f195ba4218acf69d0bad8a';

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/multi_currency_enabled', ScopeInterface::SCOPE_STORE, null, '1'],
                [
                    'payment/fortis/product_currency_primary',
                    ScopeInterface::SCOPE_STORE,
                    null,
                    $primaryCurrency
                ],
                [
                    'payment/fortis/product_currency_secondary',
                    ScopeInterface::SCOPE_STORE,
                    null,
                    $secondaryCurrency
                ],
                [
                    'payment/fortis/product_transaction_id_secondary',
                    ScopeInterface::SCOPE_STORE,
                    null,
                    $secondaryProductId
                ],
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '0'],
                [
                    'payment/fortis/product_transaction_id',
                    ScopeInterface::SCOPE_STORE,
                    null,
                    $primaryProductId
                ]
            ]);

        $this->encryptorMock
            ->method('decrypt')
            ->willReturnMap([
                [$primaryProductId, $primaryProductId],
                [$secondaryProductId, $secondaryProductId]
            ]);

        $result = $this->config->getCurrencyProductIdMap();

        $this->assertArrayHasKey($primaryCurrency, $result);
        $this->assertArrayHasKey($secondaryCurrency, $result);
        $this->assertEquals($primaryProductId, $result[$primaryCurrency]);
        $this->assertEquals($secondaryProductId, $result[$secondaryCurrency]);
    }

    /**
     * Test isCurrencySupported returns true for supported currency
     */
    public function testIsCurrencySupportedForSupportedCurrency(): void
    {
        $primaryCurrency = 'USD';
        $primaryProductId = '11f194fd87653a80a56135f7';

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/product_currency_primary', ScopeInterface::SCOPE_STORE, null, $primaryCurrency],
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '0'],
                ['payment/fortis/product_transaction_id', ScopeInterface::SCOPE_STORE, null, $primaryProductId],
                ['payment/fortis/multi_currency_enabled', ScopeInterface::SCOPE_STORE, null, '0']
            ]);

        $this->encryptorMock->method('decrypt')->willReturn($primaryProductId);

        $result = $this->config->isCurrencySupported($primaryCurrency);

        $this->assertTrue($result);
    }

    /**
     * Test isCurrencySupported returns false for unsupported currency
     */
    public function testIsCurrencySupportedForUnsupportedCurrency(): void
    {
        $primaryCurrency = 'USD';
        $primaryProductId = '11f194fd87653a80a56135f7';
        $unsupportedCurrency = 'JPY';

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/product_currency_primary', ScopeInterface::SCOPE_STORE, null, $primaryCurrency],
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '0'],
                ['payment/fortis/product_transaction_id', ScopeInterface::SCOPE_STORE, null, $primaryProductId],
                ['payment/fortis/multi_currency_enabled', ScopeInterface::SCOPE_STORE, null, '0']
            ]);

        $this->encryptorMock->method('decrypt')->willReturn($primaryProductId);

        $result = $this->config->isCurrencySupported($unsupportedCurrency);

        $this->assertFalse($result);
    }

    /**
     * Test isCurrencySupported returns false for null currency
     */
    public function testIsCurrencySupportedForNullCurrency(): void
    {
        $result = $this->config->isCurrencySupported(null);

        $this->assertFalse($result);
    }

    /**
     * Regression test: ACH should NOT use multi-currency logic
     * When ACH is active, getProductIdForCurrency should still work for CC transactions
     * but ACH transactions should use achProductId() directly
     */
    public function testAchDoesNotUseCurrencySelection(): void
    {
        $achProductId = '11f195b01c14e43e8be25944';

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['payment/fortis/fortis_ach_active', ScopeInterface::SCOPE_STORE, null, '1'],
                ['payment/fortis/fortis_ach_product_id', ScopeInterface::SCOPE_STORE, null, $achProductId]
            ]);

        $this->encryptorMock
            ->method('decrypt')
            ->with($achProductId)
            ->willReturn($achProductId);

        $result = $this->config->achProductId();

        // ACH should always use its own product ID, not currency-based selection
        $this->assertEquals($achProductId, $result);
    }

    /**
     * Test achProductId returns encrypted value properly decrypted
     */
    public function testAchProductIdDecryption(): void
    {
        $encryptedAchProductId = 'encrypted_ach_product_id';
        $decryptedAchProductId = '11f195b01c14e43e8be25944';

        $this->scopeConfigMock
            ->method('getValue')
            ->with('payment/fortis/fortis_ach_product_id', ScopeInterface::SCOPE_STORE)
            ->willReturn($encryptedAchProductId);

        $this->encryptorMock
            ->method('decrypt')
            ->with($encryptedAchProductId)
            ->willReturn($decryptedAchProductId);

        $result = $this->config->achProductId();

        $this->assertEquals($decryptedAchProductId, $result);
    }
}
