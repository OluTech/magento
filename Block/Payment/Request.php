<?php

namespace Fortispay\Fortis\Block\Payment;

use Fortispay\Fortis\Model\Payment\IFrameData;
use Magento\Csp\Helper\CspNonceProvider;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Request extends Template
{
    /**
     * @var IFrameData
     */
    private IFrameData $iFrame;

    /**
     * @var array|null
     */
    private ?array $jsConfig;

    /**
     * @var CspNonceProvider|null
     */
    private ?CspNonceProvider $cspNonceProvider;

    /**
     * Construct
     *
     * @param Context $context
     * @param IFrameData $iFrame
     * @param CspNonceProvider|null $cspNonceProvider
     * @param array $data
     */
    public function __construct(
        Context $context,
        IFrameData $iFrame,
        ?CspNonceProvider $cspNonceProvider = null,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->jsConfig         = null;
        $this->iFrame           = $iFrame;
        $this->cspNonceProvider = $cspNonceProvider;
    }

    /**
     * Prepare payment request layout data and JS asset.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function _prepareLayout(): self
    {
        $this->jsConfig = $this->iFrame->buildIFrameData();

        if ($this->jsConfig['success'] === false) {
            $this->setData('error_message', (string)$this->jsConfig['message']);
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
     * Get frontend JS configuration payload.
     *
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
