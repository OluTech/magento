<?php

namespace Fortis\Fortis\Observer;

use Fortis\Fortis\Model\FortisApi;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;

class CreateVaultPayment extends AbstractDataAssignObserver
{
    const VAULT_NAME_INDEX = 'fortis-vault-method';
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected ScopeConfigInterface $scopeConfig;
    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor          = $encryptor;
    }

    /**
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer)
    {
        // TODO: Implement in next stage
        return;

        $data    = $observer->getData();
        $payment = $data['payment'];
        $invoice = $data['invoice'];

        $order       = $invoice->getOrder();
        $outstanding = $order->getTotalDue();

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));

        $d = json_decode($payment->getAdditionalInformation()['raw_details_info']);

        if ($outstanding <= 0.0) {
            return;
        }
        // Do tokenised transaction
        $intentData = [
            'transaction_amount' => (int)(100 * $outstanding),
            'token_id' => $d->token_id,
        ];
        $api        = new FortisApi($this->scopeConfig->getValue('payment/fortis/fortis_environment'));
        $response = $api->doAuthTransaction($intentData, $user_id, $user_api_key);
    }
}
