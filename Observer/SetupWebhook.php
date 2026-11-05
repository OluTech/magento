<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Message\ManagerInterface;

class SetupWebhook implements ObserverInterface
{
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;
    /**
     * @var \Fortispay\Fortis\Model\Config
     */
    private Config $config;
    /**
     * @var \Magento\Framework\UrlInterface
     */
    private UrlInterface $urlBuilder;
    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private ManagerInterface $messageManager;
    /**
     * @var FortisApi
     */
    private FortisApi $fortisApi;

    /**
     * @param LoggerInterface $logger
     * @param EncryptorInterface $encryptor
     * @param Config $config
     * @param UrlInterface $urlBuilder
     * @param ManagerInterface $messageManager
     * @param FortisApi $fortisApi
     */
    public function __construct(
        LoggerInterface $logger,
        EncryptorInterface $encryptor,
        Config $config,
        UrlInterface $urlBuilder,
        ManagerInterface $messageManager,
        FortisApi $fortisApi
    ) {
        $this->logger         = $logger;
        $this->encryptor      = $encryptor;
        $this->config         = $config;
        $this->urlBuilder     = $urlBuilder;
        $this->messageManager = $messageManager;
        $this->fortisApi      = $fortisApi;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        try {
            $data       = $observer->getData();
            $fortisData = $data['configData']['groups']['fortis'] ?? [];
            if (empty($fortisData)) {
                return;
            }
            $this->syncWebhook(
                $this->config->achIsActive(),
                $this->config->achWebhookId(),
                'fortis_ach_webhook_id',
                'fortis_ach_webhook_url',
                'fortis/webhook/achhook',
                fn() => $this->fortisApi->createTransactionWebhook()
            );
            $this->syncWebhook(
                $this->config->apmIsActive(),
                $this->config->apmWebhookId(),
                'fortis_apm_webhook_id',
                'fortis_apm_webhook_url',
                'fortis/webhook/apmhook',
                fn() => $this->fortisApi->createApmTransactionWebhook()
            );
        } catch (\Exception $exception) {
            $this->messageManager->addExceptionMessage(
                $exception,
                __('Creating this webhook failed: ') . $exception->getMessage()
            );
        }
    }

    /**
     * Create or update the Fortis webhook for one payment product when that product is active.
     *
     * @param bool $isActive
     * @param string $existingWebhookId
     * @param string $idConfigKey
     * @param string $urlConfigKey
     * @param string $path
     * @param callable $create
     * @return void
     */
    private function syncWebhook(
        bool $isActive,
        string $existingWebhookId,
        string $idConfigKey,
        string $urlConfigKey,
        string $path,
        callable $create
    ): void {
        $url = $this->urlBuilder->getBaseUrl() . $path;

        if ($isActive) {
            if ($existingWebhookId !== '') {
                try {
                    $this->fortisApi->deleteTransactionWebhook($existingWebhookId);
                } catch (LocalizedException $exception) {
                    $this->messageManager->addExceptionMessage(
                        $exception,
                        __('Deleting old webhook failed, please try again: ') . $exception->getMessage()
                    );
                    $this->config->setConfig($idConfigKey, '');
                }
            }

            try {
                $webhookId = $create();
                if ($webhookId !== null) {
                    $this->config->setConfig($idConfigKey, $webhookId);
                    $this->messageManager->addSuccessMessage(
                        $existingWebhookId !== '' ? "You have updated webhook $url" : "You have created webhook $url"
                    );
                }
            } catch (\Exception $exception) {
                // One product failing must not stop the other product's webhook from being registered
                $this->messageManager->addExceptionMessage(
                    $exception,
                    __('Creating this webhook failed: ') . $exception->getMessage()
                );
            }
        }

        $this->config->setConfig($urlConfigKey, $url);
    }
}
