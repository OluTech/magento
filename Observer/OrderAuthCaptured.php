<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class OrderAuthCaptured implements ObserverInterface {

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
        $order          = $observer->getInvoice()->getOrder();
        $payment       = $order->getPayment();
        $orderID = $order->getId();

        $d          = json_decode($payment->getAdditionalInformation()['raw_details_info'] ?? "");
        if (!isset($d->data->auth_amount)) {
            return;
        }
        $authAmount = $d->data->auth_amount;
        $transactionId = $d->data->id;

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));

        // Do auth transaction
        $intentData = [
            'transaction_amount' => $authAmount,
            "order_number"       => $orderID,
            'transactionId'      => $transactionId,
        ];
        $api        = new FortisApi($this->scopeConfig->getValue('payment/fortis/fortis_environment'));

        $api->doCompleteAuthTransaction($intentData, $user_id, $user_api_key);
    }
}
