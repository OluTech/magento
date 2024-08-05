<?php

namespace Fortispay\Fortis\Block\Customer;

use Fortispay\Fortis\Model\Config;
use Magento\Framework\View\Element\Template;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Block\AbstractTokenRenderer;

/**
 * @api
 * @since 100.0.2
 */
class VaultAccountRenderer extends AbstractTokenRenderer
{
    /**
     * @var Config
     */
    private $config;

    /**
     * Initialize dependencies.
     *
     * @param Template\Context $context
     * @param Config $config
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
    }

    /**
     * Get Number Last 4 Digits
     *
     * @return string
     * @since 100.1.0
     */
    public function getNumberLast4Digits()
    {
        return $this->getTokenDetails()['maskedCC'];
    }

    /**
     * Get Exp Date
     *
     * @return string
     * @since 100.1.0
     */
    public function getExpDate()
    {
        return $this->getTokenDetails()['expirationDate'];
    }

    /**
     * @inheritdoc
     */
    public function getIconUrl()
    {
        return $this->config->getFortisIconImageUrl();
    }

    /**
     * @inheritdoc
     */
    public function getIconHeight()
    {
        return $this->config->getACHIcon()['height'];
    }

    /**
     * @inheritdoc
     */
    public function getIconWidth()
    {
        return $this->config->getACHIcon()['width'];
    }

    /**
     * Can render specified token
     *
     * @param PaymentTokenInterface $token
     *
     * @return boolean
     */
    public function canRender(PaymentTokenInterface $token): bool
    {
        return $token->getPaymentMethodCode() === Config::METHOD_CODE;
    }
}
