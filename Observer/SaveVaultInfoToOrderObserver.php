<?php

namespace Fortispay\Fortis\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;

class SaveVaultInfoToOrderObserver extends AbstractDataAssignObserver
{

    public const VAULT_NAME_INDEX       = 'fortis-vault-method';
    public const SURCHARGE_DATA_LITERAL = 'fortis-surcharge-data';

    /**
     * Execute
     *
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

        if (isset($additionalData[self::SURCHARGE_DATA_LITERAL])) {
            $paymentInfo->setAdditionalInformation(
                self::SURCHARGE_DATA_LITERAL,
                $additionalData[self::SURCHARGE_DATA_LITERAL]
            );
        }
    }
}
