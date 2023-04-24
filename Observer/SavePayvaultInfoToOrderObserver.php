<?php

namespace Fortispay\Fortis\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;

class SaveVaultInfoToOrderObserver extends AbstractDataAssignObserver
{

    const VAULT_NAME_INDEX = 'fortis-vault-method';

    /**
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer)
    {
        $data = $this->readDataArgument($observer);

        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additionalData) || !isset($additionalData[self::VAULT_NAME_INDEX])) {
            return;
        }

        $paymentInfo = $this->readPaymentModelArgument($observer);

        $paymentInfo->setAdditionalInformation(
            self::VAULT_NAME_INDEX,
            $additionalData[self::VAULT_NAME_INDEX]
        );
    }
}
