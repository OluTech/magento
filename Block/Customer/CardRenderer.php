<?php

namespace Fortispay\Fortis\Block\Customer;

use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Block\AbstractCardRenderer;

class CardRenderer extends AbstractCardRenderer
{
    /**
     * Can render specified token
     *
     * @param PaymentTokenInterface $token
     *
     * @return boolean
     * @since 100.1.0
     */
    public function canRender(PaymentTokenInterface $token): bool
    {
        return $token->getPaymentMethodCode() === "fortis";
    }

    /**
     * Get Number Last 4 Digits
     *
     * @return string
     * @since 100.1.0
     */
    public function getNumberLast4Digits(): string
    {
        return $this->getTokenDetails()['maskedCC'];
    }

    /**
     * Get Exp Date
     *
     * @return string
     * @since 100.1.0
     */
    public function getExpDate(): string
    {
        return $this->getTokenDetails()['expirationDate'];
    }

    /**
     * Get Icon Url
     *
     * @return string
     * @since 100.1.0
     */
    public function getIconUrl(): string
    {
        return $this->getIconForType($this->getTokenDetails()['type'])['url'];
    }

    /**
     * Get Icon Height
     *
     * @return int
     * @since 100.1.0
     */
    public function getIconHeight(): int
    {
        return $this->getIconForType($this->getTokenDetails()['type'])['height'];
    }

    /**
     * Get Icon Width
     *
     * @return int
     * @since 100.1.0
     */
    public function getIconWidth(): int
    {
        return $this->getIconForType($this->getTokenDetails()['type'])['width'];
    }
}
