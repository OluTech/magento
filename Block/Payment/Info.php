<?php

namespace Fortis\Fortis\Block\Payment;

use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Model\Config;
use Fortis\Fortis\Model\InfoFactory;

/**
 * Fortis common payment info block
 * Uses default templates
 */
class Info extends \Magento\Payment\Block\Info
{
    /**
     * @var InfoFactory
     */
    protected $_FortisInfoFactory;

    /**
     * @var Config
     */
    private $_paymentConfig;

    /**
     * @param Context $context
     * @param Config $paymentConfig
     * @param \Fortis\Fortis\Model\InfoFactory $fortisInfoFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $paymentConfig,
        InfoFactory $fortisInfoFactory,
        array $data = []
    ) {
        $this->_paymentConfig      = $paymentConfig;
        $this->_FortisInfoFactory = $fortisInfoFactory;
        parent::__construct($context, $data);
    }

}
