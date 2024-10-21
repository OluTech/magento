<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
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
        $this->fortisApi = $fortisApi;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        try {
            $data       = $observer->getData();
            $fortisData = $data['configData']['groups']['fortis'] ?? [];
            if (empty($fortisData)) {
                return;
            }
            $url = $this->urlBuilder->getBaseUrl() . 'fortis/webhook/achhook';

            $achWebhookId = $this->config->achWebhookId();
            $achIsActive  = $this->config->achIsActive();
            if ($achIsActive) {
                // Create or update the ACH webhook
                $api = $this->fortisApi;

                if ($achWebhookId !== '') {
                    $api->deleteTransactionWebhook($achWebhookId);
                    $webhookId = $api->createTransactionWebhook();
                    $this->config->setConfig('fortis_ach_webhook_id', $webhookId);
                    $this->messageManager->addSuccessMessage("You have updated webhook $url");
                } else {
                    $webhookId = $api->createTransactionWebhook();
                    $this->config->setConfig('fortis_ach_webhook_id', $webhookId);
                    $this->messageManager->addSuccessMessage("You have created webhook $url");
                }
            }

            $this->config->setConfig('fortis_ach_webhook_url', $url);
        } catch (\Exception $exception) {
            $this->messageManager->addExceptionMessage(
                $exception,
                __('Creating this webhook failed: ') . $exception->getMessage()
            );
        }
    }
}
