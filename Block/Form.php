<?php

namespace Fortispay\Fortis\Block;

use Fortispay\Fortis\Model\Config;
use Psr\Log\LoggerInterface;
use Magento\Framework\View\Element\Template\Context;

class Form extends \Magento\Payment\Block\Form
{
    /**
     * @var string Payment method code
     */
    private string $methodCode = Config::METHOD_CODE;

    /**
     * @var Config
     */
    private LoggerInterface $logger;

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     * @param array $data
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        array $data = []
    ) {
        $pre           = __METHOD__ . " : ";
        $this->logger = $logger;
        $this->logger->debug($pre . 'bof');
        parent::__construct($context, $data);
        $this->logger->debug($pre . "eof");
    }

    /**
     * Payment method code getter
     *
     * @return string
     */
    public function getMethodCode(): string
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');

        return $this->methodCode;
    }

    /**
     * Set template and redirect message
     *
     * @return void
     */
    protected function _construct()
    {
        $pre = __METHOD__ . " : ";
        $this->logger->debug($pre . 'bof');
        parent::_construct();
    }
}
