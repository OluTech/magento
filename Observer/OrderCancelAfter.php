<?php

namespace Fortispay\Fortis\Observer;

use Exception;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;

class OrderCancelAfter extends AbstractDataAssignObserver
{

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;
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
        $type = $this->scopeConfig->getValue('payment/fortis/order_intention');
        if ($type === 'sale') {
            return;
        }
        $data          = $observer->getData();
        $order         = $data['order'];
        $payment       = $order->getPayment();
        $transactionId = $payment->getLastTransId();

        if (!isset($payment->getAdditionalInformation()['raw_details_info'])) {
            return;
        }
        try {
            $paymentInfo = json_decode(
                $payment->getAdditionalInformation()['raw_details_info'],
                false,
                512,
                JSON_THROW_ON_ERROR
            );

            $authAmount = $paymentInfo->data->auth_amount;
        } catch (Exception $exception) {
            return;
        }

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));
        $type         = $this->scopeConfig->getValue('payment/fortis/order_intention');

        $api = new FortisApi($this->config);
        // Do auth transaction
        $intentData = [
            'transaction_amount' => $authAmount,
            'token_id'           => $paymentInfo->data->token_id,
            'transactionId'      => $transactionId,
        ];
        if ($type === 'auth-only') {
            $response = $api->refundAuthAmount($intentData, $user_id, $user_api_key);
        } else {
            $response = $api->refundTransactionAmount($intentData, $user_id, $user_api_key);
        }

        $trans = $this->transactionBuilder;

        if ($response) {
            // Create a void transaction
            $data = json_decode($response) ?? null;
            $data = ($data->data ?? null) ? $data->data : null;
            if (!$data) {
                return;
            }
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
