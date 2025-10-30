<?php

namespace Fortispay\Fortis\Block\Payment;

use Fortispay\Fortis\Model\Payment\IFrameData;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Request extends Template
{
    private IFrameData $iFrame;
    private ?array $jsConfig;
    private $cspNonceProvider = null;

    /**
     * Construct
     *
     * @param Context $context
     * @param IFrameData $iFrame
     * @param array $data
     */
    public function __construct(
        Context $context,
        IFrameData $iFrame,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->jsConfig = null;
        $this->iFrame   = $iFrame;
        if (class_exists('Magento\\Csp\\Helper\\CspNonceProvider')) {
            $objectManager          = \Magento\Framework\App\ObjectManager::getInstance();
            $this->cspNonceProvider = $objectManager->get('Magento\\Csp\\Helper\\CspNonceProvider');
        }
    }

    /**
     * @throws LocalizedException
     */
    public function _prepareLayout()
    {
        $this->jsConfig = $this->iFrame->buildIFrameData();

        if ($this->jsConfig['success'] === false) {
            $this->setData('error_html', '<div class="error-message error">' . $this->jsConfig['message'] . '</div>');
            return parent::_prepareLayout();
        }

        $options = [];
        if ($this->cspNonceProvider) {
            $options['attributes'] = ['nonce' => $this->cspNonceProvider->generateNonce()];
        }
        $this->pageConfig->addPageAsset(
            'Fortispay_Fortis::js/view/payment/fortis-iframe.js',
            $options
        );

        return parent::_prepareLayout();
    }

    /**
     * @return array|null
     */
    public function getJSConfig(): ?array
    {
        return $this->jsConfig;
    }

    /**
     * Get CSP Nonce if available
     *
     * @return string|null
     */
    public function getNonce(): ?string
    {
        if ($this->cspNonceProvider) {
            return $this->cspNonceProvider->generateNonce();
        }
        return null;
    }
}
