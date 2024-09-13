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
        $this->iFrame = $iFrame;
    }

    /**
     * @throws LocalizedException
     */
    public function _prepareLayout()
    {
        $this->jsConfig = $this->iFrame->buildIFrameData();

        if (!$this->jsConfig) {
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
}
