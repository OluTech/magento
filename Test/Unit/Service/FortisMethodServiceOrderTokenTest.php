<?php
/**
 * Unit tests for FortisMethodService::getFortisOrderToken()
 * Covers which payment methods the Order Then Payment form is offered for an order in each currency
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
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Model\PaymentTokenFactory;
use Magento\Vault\Model\ResourceModel\PaymentToken as PaymentTokenResourceModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// The partial Order and session doubles only supply values here
#[AllowMockObjectsWithoutExpectations]
class FortisMethodServiceOrderTokenTest extends TestCase
{
    private const CARD_USD = 'aaaaaaaaaaaaaaaaaaaaaaaa';
    private const CARD_EUR = 'bbbbbbbbbbbbbbbbbbbbbbbb';
    private const ACH = 'cccccccccccccccccccccccc';
    private const APM = 'dddddddddddddddddddddddd';

    /**
     * What was sent to Fortis to start the payment form
     *
     * @var array
     */
    private array $sent = [];

    /**
     * Run getFortisOrderToken() for an order in the given currency and return what Fortis was asked for.
     *
     * @param string $orderCurrency
     * @param bool $multiCurrency
     * @return array
     */
    private function intentionFor(string $orderCurrency, bool $multiCurrency): array
    {
        $config = $this->createStub(Config::class);
        $config->method('ccProductId')->willReturn(self::CARD_USD);
        $config->method('achIsActive')->willReturn(true);
        $config->method('achProductId')->willReturn(self::ACH);
        $config->method('apmIsActive')->willReturn(true);
        $config->method('apmProductId')->willReturn(self::APM);
        $config->method('orderAction')->willReturn('sale');
        $config->method('isMultiCurrencyEnabled')->willReturn($multiCurrency);
        $config->method('getPrimaryCurrency')->willReturn('USD');
        $config->method('getProductIdForCurrency')
            ->willReturn($multiCurrency && $orderCurrency === 'EUR' ? self::CARD_EUR : self::CARD_USD);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getOrderCurrencyCode', 'getTotalDue', 'getTaxAmount', 'addCommentToStatusHistory'])
            ->getMock();
        $order->method('getId')->willReturn(42);
        $order->method('getOrderCurrencyCode')->willReturn($orderCurrency);
        $order->method('getTotalDue')->willReturn(5.0);
        $order->method('getTaxAmount')->willReturn(0.0);
        $session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLastRealOrder'])
            ->getMock();
        $session->method('getLastRealOrder')->willReturn($order);

        $api = $this->createStub(FortisApi::class);
        $api->method('getClientToken')->willReturnCallback(function (array $intentData) {
            $this->sent = $intentData;

            return 'header.payload.signature';
        });
        $api->method('getTokenBody')->willReturn(json_encode([
            'transaction' => ['methods' => [['product_transaction_id' => self::CARD_USD]]],
        ]));

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('decrypted');

        $currencyOptions = $this->createStub(CurrencyOptions::class);
        $currencyOptions->method('toArray')->willReturn(['USD' => 'US Dollar', 'EUR' => 'Euro']);

        $service = new FortisMethodService(
            $config,
            $this->createStub(LoggerInterface::class),
            $this->createStub(File::class),
            $this->createStub(DirectoryList::class),
            $session,
            $this->createStub(UrlInterface::class),
            $encryptor,
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(PaymentTokenRepositoryInterface::class),
            $this->createStub(PaymentTokenManagementInterface::class),
            $this->createStub(PaymentTokenFactory::class),
            $this->createStub(PaymentTokenResourceModel::class),
            $this->createStub(OrderRepositoryInterface::class),
            $this->createStub(FileDriver::class),
            $api,
            $this->createStub(Builder::class),
            $this->createStub(MagentoOrderService::class),
            $this->createStub(CheckoutProcessor::class),
            $currencyOptions
        );
        $service->getFortisOrderToken(false);

        return $this->sent;
    }

    /**
     * The method types and products the form was asked to offer
     *
     * @param array $intention
     * @return array
     */
    private static function methodsOf(array $intention): array
    {
        $methods = [];
        foreach ($intention['methods'] ?? [] as $method) {
            $methods[$method['type']] = $method['product_transaction_id'];
        }

        return $methods;
    }

    /**
     * An order in the primary currency is offered the card, bank account and PayPal products.
     */
    public function testPrimaryCurrencyOrderIsOfferedEveryMethod(): void
    {
        $methods = self::methodsOf($this->intentionFor('USD', true));

        $this->assertSame(['cc' => self::CARD_USD, 'ach' => self::ACH, 'apm' => self::APM], $methods);
    }

    /**
     * Fortis cannot draw one form for methods in different currencies: a secondary-currency order gets the card only.
     */
    public function testSecondaryCurrencyOrderIsOfferedTheCardProductOnly(): void
    {
        $intention = $this->intentionFor('EUR', true);

        $this->assertSame(['cc' => self::CARD_EUR], self::methodsOf($intention));
        $this->assertSame('EUR', $intention['currency_code']);
    }

    /**
     * A store that does not use multi-currency keeps every method, whatever currency it sells in.
     */
    public function testStoreWithoutMultiCurrencyKeepsEveryMethod(): void
    {
        $methods = self::methodsOf($this->intentionFor('EUR', false));

        $this->assertSame(['cc' => self::CARD_USD, 'ach' => self::ACH, 'apm' => self::APM], $methods);
    }
}
