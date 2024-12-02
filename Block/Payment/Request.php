<?php

namespace Fortispay\Fortis\Block\Payment;

use Fortispay\Fortis\Model\Payment\IFrameData;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Csp\Helper\CspNonceProvider;

class Request extends Template
{
    private IFrameData $iFrame;
    private ?array $jsConfig;
    private CspNonceProvider $cspNonceProvider;

    /**
     * Construct
     *
     * @param Context $context
     * @param IFrameData $iFrame
     * @param CspNonceProvider $cspNonceProvider
     * @param array $data
     */
    public function __construct(
        Context $context,
        IFrameData $iFrame,
        CspNonceProvider $cspNonceProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->jsConfig         = null;
        $this->iFrame           = $iFrame;
        $this->cspNonceProvider = $cspNonceProvider;
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

        $this->pageConfig->addPageAsset(
            'Fortispay_Fortis::js/view/payment/fortis-iframe.js',
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
     * Get CSP Nonce
     *
     * @return String
     * @throws LocalizedException
     */
    public function getNonce(): string
    {
        return $this->cspNonceProvider->generateNonce();
    }
}
