<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;

class CancelOrderAuthorisation implements ObserverInterface
{
    /**
     * @var ScopeConfigInterface
     */
    protected ScopeConfigInterface $scopeConfig;
    /**
     * @var Builder
     */
    private Builder $transactionBuilder;
    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;
    /**
     * @var \Fortispay\Fortis\Model\Config
     */
    private Config $config;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Builder $transactionBuilder
     * @param EncryptorInterface $encryptor
     * @param \Fortispay\Fortis\Model\Config $config
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Builder $transactionBuilder,
        EncryptorInterface $encryptor,
        Config $config
    ) {
        $this->scopeConfig        = $scopeConfig;
        $this->transactionBuilder = $transactionBuilder;
        $this->encryptor          = $encryptor;
        $this->config             = $config;
    }

    /**
     * Execute
     *
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer)
    {
        $data          = $observer->getData();
        $payment       = $data['payment'];
        $transactionId = $payment->getLastTransId();
        $order         = $payment->getOrder();

        $d          = json_decode($payment->getAdditionalInformation()['raw_details_info']);
        $authAmount = $d->auth_amount;

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));
        $type         = $this->scopeConfig->getValue('payment/fortis/order_intention');

        // Do auth transaction
        $intentData = [
            'transaction_amount' => $authAmount,
            'token_id'           => $d->token_id,
            'transactionId'      => $transactionId,
        ];
        $api        = new FortisApi($this->config);
        if ($type === 'auth-only') {
            $response = $api->refundAuthAmount($intentData, $user_id, $user_api_key);
        } else {
            $response = $api->refundTransactionAmount($intentData, $user_id, $user_api_key);
        }

        $trans = $this->transactionBuilder;

        if ($response) {
            // Create a void transaction
            $data       = json_decode($response)->data;
            $newPayment = $order->getPayment();
            $newPayment->setAmountAuthorized($authAmount / 100.0);
            $payment->setLastTransId($data->id)
                    ->setTransactionId($data->id)
                    ->setAdditionalInformation(
                        [Transaction::RAW_DETAILS => json_encode($response)]
                    );
            $transaction = $trans->setPayment($payment)
                                 ->setOrder($order)
                                 ->setTransactionId($data->id)
                                 ->setAdditionalInformation([Transaction::RAW_DETAILS => json_encode($response)])
                                 ->setFailSafe(true)
                                 ->build(Transaction::TYPE_VOID);

            $message = __('The authorised amount has been voided');
            $payment->addTransactionCommentsToOrder($transaction, $message);
            $payment->setParentTransactionId($transactionId);
            $payment->save();
            $order->setShouldCloseParentTransaction(true);
            $order->setStatus(Order::STATE_CLOSED);
            $order->setState(Order::STATE_CLOSED);
            $order->save();
        }
    }
}
