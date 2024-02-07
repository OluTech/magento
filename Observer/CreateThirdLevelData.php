<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Store\Model\StoreManagerInterface;

class CreateThirdLevelData implements ObserverInterface
{
    /**
     * @var ScopeConfigInterface
     */
    protected ScopeConfigInterface $scopeConfig;
    /**
     * @var Builder
     */
    private Builder $transactionBuilder;
    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;
    /**
     * @var \Fortispay\Fortis\Model\Config
     */
    private Config $config;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Builder $transactionBuilder
     * @param EncryptorInterface $encryptor
     * @param \Fortispay\Fortis\Model\Config $config
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Builder $transactionBuilder,
        EncryptorInterface $encryptor,
        Config $config,
        StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig        = $scopeConfig;
        $this->transactionBuilder = $transactionBuilder;
        $this->encryptor          = $encryptor;
        $this->config             = $config;
        $this->storeManager       = $storeManager;
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
        $order          = $d['order'];
        $storeManager   = $d['storeManager'];
        $accountType    = $d['type'];
        $countryFactory = $d['countryFactory'];
        $api            = new FortisApi($this->config);
        $transactionId  = $d['transactionId'];

        if ($accountType === 'visa') {
            $api->createVisaLevel3Entry($order, $storeManager, $countryFactory, $transactionId);
        } elseif ($accountType === 'mc') {
            $api->createMcLevel3Entry($order, $storeManager, $countryFactory, $transactionId);
        }
    }
}
