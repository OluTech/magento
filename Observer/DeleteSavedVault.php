<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class DeleteSavedVault implements ObserverInterface {

    /**
     * @var ScopeConfigInterface
     */
    protected ScopeConfigInterface $scopeConfig;

    private EncryptorInterface $encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig        = $scopeConfig;
        $this->encryptor          = $encryptor;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $dataObject = $observer->getEvent()->getData()["object"];
        $cardData = $dataObject->getData();

        if ($cardData["is_visible"]) {
            return;
        }

        $tokenID = $cardData["gateway_token"];

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));

        // Do auth transaction
        $intentData = [
            'tokenId'      => $tokenID,
        ];
        $api        = new FortisApi($this->scopeConfig->getValue('payment/fortis/fortis_environment'));

        $api->doTokenCCDelete($intentData, $user_id, $user_api_key);
    }
}
