<?php
/**
 * Unit tests for SetupWebhook observer
 * Covers registering the ACH and APM transaction webhooks when the payment configuration is saved
 */

namespace Fortispay\Fortis\Test\Unit\Observer;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Observer\SetupWebhook;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SetupWebhookTest extends TestCase
{
    /**
     * @var Config|\PHPUnit\Framework\MockObject\Stub
     */
    private $config;

    /**
     * @var FortisApi|\PHPUnit\Framework\MockObject\MockObject|\PHPUnit\Framework\MockObject\Stub
     */
    private $fortisApi;

    /**
     * @var ManagerInterface|\PHPUnit\Framework\MockObject\MockObject|\PHPUnit\Framework\MockObject\Stub
     */
    private $messageManager;

    /**
     * @var array<string, string>
     */
    private array $savedConfig = [];

    protected function setUp(): void
    {
        $this->savedConfig = [];
        $this->config = $this->createStub(Config::class);
        $this->config->method('setConfig')->willReturnCallback(function (string $key, string $value): void {
            $this->savedConfig[$key] = $value;
        });
        $this->fortisApi = $this->createStub(FortisApi::class);
        $this->messageManager = $this->createStub(ManagerInterface::class);
    }

    /**
     * Run the observer for a saved Fortis configuration
     *
     * @param array $groups
     */
    private function runObserver(array $groups = ['fortis' => ['fields' => []]]): void
    {
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getBaseUrl')->willReturn('https://shop.example.com/');

        $observer = new SetupWebhook(
            $this->createStub(LoggerInterface::class),
            $this->createStub(EncryptorInterface::class),
            $this->config,
            $urlBuilder,
            $this->messageManager,
            $this->fortisApi
        );

        $event = new Observer();
        $event->setData('configData', ['groups' => $groups]);
        $observer->execute($event);
    }

    /**
     * Enabling APM registers the APM webhook and stores its id and url
     */
    public function testApmWebhookIsCreatedWhenApmIsActive(): void
    {
        $this->fortisApi = $this->createMock(FortisApi::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->config->method('apmIsActive')->willReturn(true);
        $this->config->method('apmWebhookId')->willReturn('');
        $this->fortisApi->expects($this->once())->method('createApmTransactionWebhook')->willReturn('apm-hook-1');
        $this->fortisApi->expects($this->never())->method('createTransactionWebhook');
        $this->fortisApi->expects($this->never())->method('deleteTransactionWebhook');
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with('You have created webhook https://shop.example.com/fortis/webhook/apmhook');

        $this->runObserver();

        $this->assertSame('apm-hook-1', $this->savedConfig['fortis_apm_webhook_id']);
        $this->assertSame(
            'https://shop.example.com/fortis/webhook/apmhook',
            $this->savedConfig['fortis_apm_webhook_url']
        );
    }

    /**
     * An existing APM webhook is deleted and registered again
     */
    public function testExistingApmWebhookIsReplaced(): void
    {
        $this->fortisApi = $this->createMock(FortisApi::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->config->method('apmIsActive')->willReturn(true);
        $this->config->method('apmWebhookId')->willReturn('old-apm-hook');
        $this->fortisApi->expects($this->once())->method('deleteTransactionWebhook')->with('old-apm-hook');
        $this->fortisApi->expects($this->once())->method('createApmTransactionWebhook')->willReturn('apm-hook-2');
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with('You have updated webhook https://shop.example.com/fortis/webhook/apmhook');

        $this->runObserver();

        $this->assertSame('apm-hook-2', $this->savedConfig['fortis_apm_webhook_id']);
    }

    /**
     * ACH and APM each get their own webhook, product and url
     */
    public function testAchAndApmWebhooksAreRegisteredSeparately(): void
    {
        $this->config->method('achIsActive')->willReturn(true);
        $this->config->method('apmIsActive')->willReturn(true);
        $this->config->method('achWebhookId')->willReturn('');
        $this->config->method('apmWebhookId')->willReturn('');
        $this->fortisApi->method('createTransactionWebhook')->willReturn('ach-hook-1');
        $this->fortisApi->method('createApmTransactionWebhook')->willReturn('apm-hook-1');

        $this->runObserver();

        $this->assertSame('ach-hook-1', $this->savedConfig['fortis_ach_webhook_id']);
        $this->assertSame('apm-hook-1', $this->savedConfig['fortis_apm_webhook_id']);
        $this->assertSame(
            'https://shop.example.com/fortis/webhook/achhook',
            $this->savedConfig['fortis_ach_webhook_url']
        );
        $this->assertSame(
            'https://shop.example.com/fortis/webhook/apmhook',
            $this->savedConfig['fortis_apm_webhook_url']
        );
    }

    /**
     * With ACH and APM off no webhook is registered
     */
    public function testNothingIsRegisteredWhenBothAreInactive(): void
    {
        $this->fortisApi = $this->createMock(FortisApi::class);
        $this->fortisApi->expects($this->never())->method('createTransactionWebhook');
        $this->fortisApi->expects($this->never())->method('createApmTransactionWebhook');

        $this->runObserver();

        $this->assertArrayNotHasKey('fortis_apm_webhook_id', $this->savedConfig);
        $this->assertArrayNotHasKey('fortis_ach_webhook_id', $this->savedConfig);
    }

    /**
     * A failing ACH webhook does not stop the APM webhook from being registered
     */
    public function testApmWebhookIsStillRegisteredWhenAchFails(): void
    {
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->config->method('achIsActive')->willReturn(true);
        $this->config->method('apmIsActive')->willReturn(true);
        $this->config->method('achWebhookId')->willReturn('');
        $this->config->method('apmWebhookId')->willReturn('');
        $this->fortisApi->method('createTransactionWebhook')
            ->willThrowException(new LocalizedException(new Phrase('ACH rejected')));
        $this->fortisApi->method('createApmTransactionWebhook')->willReturn('apm-hook-1');
        $this->messageManager->expects($this->once())->method('addExceptionMessage');

        $this->runObserver();

        $this->assertSame('apm-hook-1', $this->savedConfig['fortis_apm_webhook_id']);
    }

    /**
     * Saving another payment method's configuration leaves the webhooks alone
     */
    public function testOtherConfigurationGroupsAreIgnored(): void
    {
        $this->fortisApi = $this->createMock(FortisApi::class);
        $this->fortisApi->expects($this->never())->method('createApmTransactionWebhook');

        $this->runObserver(['other' => ['fields' => []]]);

        $this->assertSame([], $this->savedConfig);
    }
}
