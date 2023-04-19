<?php

namespace Fortis\Fortis\Observer;

use Fortis\Fortis\Model\FortisApi;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
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
     * @var \Psr\Log\LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    private EncryptorInterface $encryptor;
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        EncryptorInterface $encryptor
    ) {
        $this->logger      = $logger;
        $this->encryptor   = $encryptor;
        $this->scopeConfig = $scopeConfig;
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

        $intentData = [
            'transaction_amount' => $refundAmount,
            'transactionId'      => $payment->getLastTransId(),
            'description'        => $order->getIncrementId(),
        ];
        $api        = new FortisApi($this->scopeConfig->getValue('payment/fortis/fortis_environment'));
        if ($type === 'auth-only') {
            $response = $api->refundAuthAmount($intentData, $user_id, $user_api_key);
        } else {
            $response = $api->refundTransactionAmount($intentData, $user_id, $user_api_key);
        }
    }
}
