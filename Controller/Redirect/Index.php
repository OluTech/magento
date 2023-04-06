<?php

namespace Fortis\Fortis\Controller\Redirect;

use Exception;
use Fortis\Fortis\Controller\AbstractFortis;
use Fortis\Fortis\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Index extends AbstractFortis
{
    const CARTURL = 'checkout/cart';
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
            $this->_redirect(self::CARTURL);
        } catch (Exception $e) {
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Fortis Checkout.'));
            $this->_redirect(self::CARTURL);
        }

        $block = $page_object->getLayout()
                             ->getBlock('fortis_redirect')
                             ->setPaymentFormData($order ?? null);

        $formData = $block->getSubmitForm();
        if (! $formData) {
            $this->_logger->error("We can\'t start Fortis Checkout.");
            $this->_redirect(self::CARTURL);
        }

        return $page_object;
    }
}
