<?php

namespace Fortispay\Fortis\Controller\Redirect;

use Exception;
use Fortispay\Fortis\Controller\AbstractFortis;
use Fortispay\Fortis\Model\Config;
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
            return $this->getRedirectToCartObject();
        } catch (Exception $e) {
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Fortis Checkout.'));
            return $this->getRedirectToCartObject();
        }

        $block = $page_object->getLayout()
                             ->getBlock('fortis_redirect')
                             ->setPaymentFormData($order ?? null);

        $formData = $block->getSubmitForm();
        $successURL = $block->getSuccessURL();
        if (!$formData && !$successURL) {
            $this->_logger->error("We can\'t start Fortis Checkout.");
            return $this->getRedirectToCartObject();
        }

        return $page_object;
    }

    public function getResponse()
    {
        return $this->getResponse();
    }
}
