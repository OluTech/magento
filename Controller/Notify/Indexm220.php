<?php

namespace Fortispay\Fortis\Controller\Notify;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Fortispay\Fortis\Controller\AbstractFortis;
use Fortispay\Fortis\Model\Config;

class Indexm220 extends AbstractFortis
{
    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * Config method type
     *
     * @var string
     */
    protected $_configMethod = Config::METHOD_CODE;

    /**
     * Execute
     */
    public function execute()
    {
        $pre = __METHOD__ . " : ";

        $page_object = $this->pageFactory->create();

        try {
            $this->_initCheckout();
        } catch (LocalizedException $e) {
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
            $this->_redirect('checkout/cart');
        } catch (Exception $e) {
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Fortis Checkout.'));
            $this->_redirect('checkout/cart');
        }

        return $page_object;
    }
}
