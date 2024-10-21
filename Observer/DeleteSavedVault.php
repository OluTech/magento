<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class DeleteSavedVault implements ObserverInterface
{

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;
    private EncryptorInterface $encryptor;
    private FortisApi $fortisApi;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param FortisApi $fortisApi
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        FortisApi $fortisApi
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor   = $encryptor;
        $this->fortisApi = $fortisApi;
    }

    public function execute(Observer $observer)
    {
        $dataObject = $observer->getEvent()->getData()["object"];
        $cardData   = $dataObject->getData();

        if ($cardData["is_visible"]) {
            return;
        }

        $tokenID = $cardData["gateway_token"];

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));

        // Do auth transaction
        $intentData = [
            'tokenId' => $tokenID,
        ];
        $api        = $this->fortisApi;

        $api->doTokenCCDelete($intentData, $user_id, $user_api_key);
    }
}
