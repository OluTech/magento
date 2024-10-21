<?php

namespace Fortispay\Fortis\Observer;

use Exception;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Sales\Api\OrderRepositoryInterface;
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
    private OrderRepositoryInterface $orderRepository;
    private FortisApi $fortisApi;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Builder $transactionBuilder
     * @param EncryptorInterface $encryptor
     * @param OrderRepositoryInterface $orderRepository
     * @param FortisApi $fortisApi
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Builder $transactionBuilder,
        EncryptorInterface $encryptor,
        OrderRepositoryInterface $orderRepository,
        FortisApi $fortisApi
    ) {
        $this->scopeConfig        = $scopeConfig;
        $this->transactionBuilder = $transactionBuilder;
        $this->encryptor          = $encryptor;
        $this->orderRepository    = $orderRepository;
        $this->fortisApi          = $fortisApi;
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
        $order   = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();

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

            $authAmount = $paymentInfo->auth_amount;
        } catch (Exception $exception) {
            return;
        }

        $transactionId = $paymentInfo?->id;

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));

        $api = $this->fortisApi;
        // Do auth transaction
        $intentData = [
            'transaction_amount' => $authAmount,
            'token_id'           => $paymentInfo->token_id,
            'transactionId'      => $transactionId,
        ];

        $response = $api->voidAuthAmount($intentData, $user_id, $user_api_key);

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
            $order->setShouldCloseParentTransaction(true);
            $order->setStatus(Order::STATE_CLOSED);
            $order->setState(Order::STATE_CLOSED);
            $this->orderRepository->save($order);
        }
    }
}
