<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CreateThirdLevelData implements ObserverInterface
{
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private FortisApi $fortisApi;

    /**
     * @param FortisApi $fortisApi
     */
    public function __construct(
        FortisApi $fortisApi
    ) {
        $this->fortisApi = $fortisApi;
    }

    /**
     * Execute
     *
     * @param Observer $observer
     *
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Exception
     */
    public function execute(Observer $observer): void
    {
        $d              = $observer->getData();
        $order          = $observer->getEvent()->getOrder();
        $storeManager   = $d['storeManager'];
        $accountType    = $d['type'];
        $countryFactory = $d['countryFactory'];
        $api            = $this->fortisApi;
        $transactionId  = $d['transactionId'];

        if ($accountType === 'visa') {
            $api->createVisaLevel3Entry($order, $storeManager, $countryFactory, $transactionId);
        } elseif ($accountType === 'mc') {
            $api->createMcLevel3Entry($order, $storeManager, $countryFactory, $transactionId);
        }
    }
}
