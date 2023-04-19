<?php

namespace Fortispay\Fortis\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;

class SavePaymentTypeToOrderObserver extends AbstractDataAssignObserver
{

    const FORTIS_PAYMENT_TYPE = 'fortis-payment-type';

    /**
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer)
    {
        $data = $this->readDataArgument($observer);

        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additionalData) || !isset($additionalData[self::FORTIS_PAYMENT_TYPE])) {
            return;
        }

        $paymentInfo = $this->readPaymentModelArgument($observer);

        $paymentInfo->setAdditionalInformation(
            self::FORTIS_PAYMENT_TYPE,
            $additionalData[self::FORTIS_PAYMENT_TYPE]
        );
    }
}
