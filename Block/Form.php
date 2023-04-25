<?php

namespace Fortispay\Fortis\Block;

use Fortispay\Fortis\Helper\Data;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\ConfigFactory;
use Fortispay\Fortis\Model\Fortis\Checkout;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Element\Template\Context;

class Form extends \Magento\Payment\Block\Form
{
    /**
     * @var string Payment method code
     */
    protected $_methodCode = Config::METHOD_CODE;

    /**
     * @var Data
     */
    protected $_fortisData;

    /**
     * @var ConfigFactory
     */
    protected ConfigFactory $fortisConfigFactory;

    /**
     * @var ResolverInterface
     */
    protected $_localeResolver;

    /**
     * @var Config
     */
    protected $_config;

    /**
     * @var bool
     */
    protected $_isScopePrivate;

    /**
     * @var CurrentCustomer
     */
    protected $currentCustomer;

    /**
     * @param Context $context
     * @param ConfigFactory $fortisConfigFactory
     * @param ResolverInterface $localeResolver
     * @param Data $fortisData
     * @param CurrentCustomer $currentCustomer
     * @param array $data
     */
    public function __construct(
        Context $context,
        ConfigFactory $fortisConfigFactory,
        ResolverInterface $localeResolver,
        Data $fortisData,
        CurrentCustomer $currentCustomer,
        array $data = []
    ) {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
        $this->_fortisData         = $fortisData;
        $this->fortisConfigFactory = $fortisConfigFactory;
        $this->_localeResolver     = $localeResolver;
        $this->_config             = null;
        $this->_isScopePrivate     = true;
        $this->currentCustomer     = $currentCustomer;
        parent::__construct($context, $data);
        $this->_logger->debug($pre . "eof");
    }

    /**
     * Payment method code getter
     *
     * @return string
     */
    public function getMethodCode()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');

        return $this->_methodCode;
    }

    /**
     * Set template and redirect message
     *
     * @return null
     */
    protected function _construct()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
        $this->_config = $this->fortisConfigFactory->create()->setMethod($this->getMethodCode());
        parent::_construct();
    }
}
