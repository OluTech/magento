<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CreateThirdLevelData implements ObserverInterface
{
    /**
     * @var FortisApi
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
        $storeManager   = $d['storeManager'] ?? null;
        $accountType    = $d['type'] ?? null;
        $countryFactory = $d['countryFactory'] ?? null;
        $transactionId  = $d['transactionId'] ?? null;

        if (!$order || !$storeManager || !$accountType || !$countryFactory || !$transactionId) {
            return;
        }

        $api = $this->fortisApi;

        if ($accountType === 'visa') {
            $api->createVisaLevel3Entry($order, $storeManager, $countryFactory, $transactionId);
        } elseif ($accountType === 'mc') {
            $api->createMcLevel3Entry($order, $storeManager, $countryFactory, $transactionId);
        }
    }
}
