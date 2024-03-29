<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class SaleRefund implements ObserverInterface
{
    /**
     * Common fields in invoice and credit memo
     *
     * @var array|string[]
     */
    private array $fields = [
        'base_currency_code',
        'base_discount_amount',
        'base_discount_tax_compensation_amount',
        'base_grand_total',
        'base_shipping_amount',
        'base_shipping_discount_tax_compensation_amnt',
        'base_shipping_incl_tax',
        'base_shipping_tax_amount',
        'base_subtotal',
        'base_subtotal_incl_tax',
        'base_tax_amount',
        'base_to_global_rate',
        'base_to_order_rate',
        'discount_amount',
        'discount_description',
        'discount_tax_compensation_amount',
        'email_sent',
        'global_currency_code',
        'grand_total',
        'increment_id',
        'invoice_id',
        'order_currency_code',
        'order_id',
        'send_email',
        'shipping_address_id',
        'shipping_amount',
        'shipping_discount_tax_compensation_amount',
        'shipping_incl_tax',
        'shipping_tax_amount',
        'state',
        'store_currency_code',
        'store_id',
        'store_to_base_rate',
        'store_to_order_rate',
        'subtotal',
        'subtotal_incl_tax',
        'tax_amount',
    ];

    /**
     * Common fields in invoice and credit memo items
     *
     * @var array|string[]
     */
    private array $itemFields = [
        'base_price',
        'tax_amount',
        'base_row_total',
        'discount_amount',
        'row_total',
        'base_discount_amount',
        'price_incl_tax',
        'base_tax_amount',
        'base_price_incl_tax',
        'qty',
        'base_cost',
        'price',
        'base_row_total_incl_tax',
        'row_total_incl_tax',
        'discount_tax_compensation_amount',
        'base_discount_tax_compensation_amount',
        'weee_tax_applied_amount',
        'weee_tax_applied_row_amount',
        'weee_tax_disposition',
        'weee_tax_row_disposition',
        'base_weee_tax_applied_amount',
        'base_weee_tax_applied_row_amnt',
        'base_weee_tax_disposition',
        'base_weee_tax_row_disposition',
    ];
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;
    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;
    /**
     * @var \Fortispay\Fortis\Model\Config
     */
    private Config $config;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param EncryptorInterface $encryptor
     * @param \Fortispay\Fortis\Model\Config $config
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        EncryptorInterface $encryptor,
        Config $config
    ) {
        $this->logger      = $logger;
        $this->encryptor   = $encryptor;
        $this->scopeConfig = $scopeConfig;
        $this->config      = $config;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        $data       = $observer->getData();
        $creditMemo = $data['creditmemo'];
        $order      = $creditMemo->getOrder();
        $payment    = $order->getPayment();

        $refundAmount = (int)(100 * $creditMemo->getGrandTotal());

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));
        $type         = $this->scopeConfig->getValue('payment/fortis/order_intention');

        $paymentMethod         = 'cc';
        $additionalInformation = $payment->getAdditionalInformation();
        if (!empty($additionalInformation) && !empty($additionalInformation['raw_details_info'])) {
            $additionalInfo = json_decode($additionalInformation['raw_details_info']);
            $paymentMethod  = $additionalInfo->payment_method;
        }

        $intentData = [
            'transaction_amount' => $refundAmount,
            'transactionId'      => $payment->getLastTransId(),
            'description'        => $order->getIncrementId(),
        ];
        $api        = new FortisApi($this->config);
        if ($type === 'auth-only') {
            $api->refundAuthAmount($intentData, $user_id, $user_api_key);
        } else {
            if ($paymentMethod !== 'ach') {
                $api->refundTransactionAmount($intentData, $user_id, $user_api_key);
            } else {
                $intentData = [
                    'transaction_amount'      => $refundAmount,
                    'description'             => $order->getIncrementId(),
                    'previous_transaction_id' => $additionalInfo?->id,
                ];
                $api->achRefundTransactionAmount($intentData);
            }
        }
    }
}
