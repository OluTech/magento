<?php

namespace Fortispay\Fortis\Helper;

use Magento\Framework\App\Config\BaseFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Psr\Log\LoggerInterface;

/**
 * Fortis Data helper
 */
class Cron extends AbstractHelper
{

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * Construct
     *
     * @param Context $context
     */
    public function __construct(
        Context $context
    ) {
        $this->_logger = $context->getLogger();
    }
}
