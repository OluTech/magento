<?php

namespace Fortispay\Fortis\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;

class PaymentDataAssignObserver extends AbstractDataAssignObserver
{
    public const VAULT_NAME_INDEX       = 'fortis-vault-method';
    public const SURCHARGE_DATA_LITERAL = 'fortis-surcharge-data';
    public const FORTIS_PAYMENT_TYPE    = 'fortis-payment-type';

    /**
     * Execute
     *
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer)
    {
        $data           = $this->readDataArgument($observer);
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);

        if (!is_array($additionalData)) {
            return;
        }

        $paymentInfo = $this->readPaymentModelArgument($observer);

        // Handle vault info
        if (isset($additionalData[self::VAULT_NAME_INDEX])) {
            $paymentInfo->setAdditionalInformation(
                self::VAULT_NAME_INDEX,
                $additionalData[self::VAULT_NAME_INDEX]
            );
        }

        // Handle surcharge data
        if (isset($additionalData[self::SURCHARGE_DATA_LITERAL])) {
            $paymentInfo->setAdditionalInformation(
                self::SURCHARGE_DATA_LITERAL,
                $additionalData[self::SURCHARGE_DATA_LITERAL]
            );
        }

        // Handle payment type
        if (isset($additionalData[self::FORTIS_PAYMENT_TYPE])) {
            $paymentInfo->setAdditionalInformation(
                self::FORTIS_PAYMENT_TYPE,
                $additionalData[self::FORTIS_PAYMENT_TYPE]
            );
        }
    }
}
