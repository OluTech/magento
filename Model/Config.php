<?php

// @codingStandardsIgnoreFile

namespace Fortispay\Fortis\Model;

use Magento\Directory\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Config model that is aware of all \Fortispay\Fortis payment methods
 * Works with Fortis-specific system configuration
 * @SuppressWarnings(PHPMD.ExcesivePublicCount)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Config extends AbstractConfig
{

    /**
     * @var Fortis this is a model which we will use.
     */
    public const METHOD_CODE = 'fortis';

    public const ACH_ICON = [
      'width' => 50,
      'height' => 33
    ];

    /**
     * @var Data
     */
    protected $directoryHelper;

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var string[]
     */
    protected $_supportedBuyerCountryCodes = ['ZA'];

    /**
     * Currency codes supported by Fortis methods
     * @var string[]
     */
    protected $_supportedCurrencyCodes = ['USD', 'EUR', 'GPD', 'ZAR'];

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var UrlInterface
     */
    protected $_urlBuilder;

    /**
     * @var Repository
     */
    protected $_assetRepo;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    private EncryptorInterface $encryptor;
    /**
     * @var \Magento\Framework\App\Config\Storage\WriterInterface
     */
    private WriterInterface $configWriter;

    /**
     * Construct
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param Data $directoryHelper
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param Repository $assetRepo
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Data $directoryHelper,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        Repository $assetRepo,
        EncryptorInterface $encryptor,
        WriterInterface $configWriter
    ) {
        $this->_logger = $logger;
        parent::__construct($scopeConfig);
        $this->directoryHelper = $directoryHelper;
        $this->_storeManager   = $storeManager;
        $this->_assetRepo      = $assetRepo;
        $this->scopeConfig     = $scopeConfig;
        $METHOD_CODE           = self::METHOD_CODE;
        $this->setMethod($METHOD_CODE);
        $currentStoreId = $this->_storeManager->getStore()->getStoreId();
        $this->setStoreId($currentStoreId);
        $this->encryptor    = $encryptor;
        $this->configWriter = $configWriter;
    }

    /**
     * @return string
     */
    public function environment(): string
    {
        return $this->getConfig('fortis_environment');
    }

    /**
     * Return buyer country codes supported by Fortis
     *
     * @return string[]
     */
    public function getSupportedBuyerCountryCodes()
    {
        return $this->_supportedBuyerCountryCodes;
    }

    /**
     * Return merchant country code, use default country if it not specified in General settings
     *
     * @return string
     */
    public function getMerchantCountry()
    {
        return $this->directoryHelper->getDefaultCountry($this->_storeId);
    }

    /**
     * Is Method Supported For Country
     *
     * Check whether method supported for specified country or not
     * Use $_methodCode and merchant country by default
     *
     * @param string|null $method
     * @param string|null $countryCode
     *
     * @return bool
     */
    public function isMethodSupportedForCountry($method = null, $countryCode = null)
    {
        if ($method === null) {
            $method = $this->getMethodCode();
        }

        if ($countryCode === null) {
            $countryCode = $this->getMerchantCountry();
        }

        return in_array($method, $this->getCountryMethods($countryCode));
    }

    /**
     * Return list of allowed methods for specified country iso code
     *
     * @param string|null $countryCode 2-letters iso code
     *
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getCountryMethods($countryCode = null)
    {
        $countryMethods = [
            'other' => [
                self::METHOD_CODE,
            ],

        ];
        if ($countryCode === null) {
            return $countryMethods;
        }

        return isset($countryMethods[$countryCode]) ? $countryMethods[$countryCode] : $countryMethods['other'];
    }

    /**
     * Get Fortis "mark" image URL
     *
     * @return string
     */
    public function getPaymentMarkImageUrl()
    {
        if ($this->achIsActive()) {
            return $this->_assetRepo->getUrl('Fortispay_Fortis::images/logo-ach.png');
        }

        return $this->_assetRepo->getUrl('Fortispay_Fortis::images/logo.png');
    }

    /**
     * Get Fortis icon image URL
     *
     * @return string
     */
    public function getFortisIconImageUrl()
    {
        return $this->_assetRepo->getUrl('Fortispay_Fortis::images/fortis_ach.png');
    }

    /**
     * Get "What Is Fortis" localized URL; Supposed to be used with "mark" as popup window
     *
     * @return string
     */
    public function getPaymentMarkWhatIsFortis()
    {
        return 'Fortis Payment Gateway';
    }

    /**
     * Mapper from Fortis-specific payment actions to Magento payment actions
     *
     * @return string|null
     */
    public function getPaymentAction()
    {
        // TODO: Update for Fortis
        $paymentAction = null;
        $pre           = __METHOD__ . ' : ';
        $this->_logger->debug($pre . 'bof');

        $action = $this->getValue('paymentAction');

        switch ($action) {
            case self::PAYMENT_ACTION_AUTH:
                $paymentAction = AbstractMethod::ACTION_AUTHORIZE;
                break;
            case self::PAYMENT_ACTION_SALE:
                $paymentAction = AbstractMethod::ACTION_AUTHORIZE_CAPTURE;
                break;
            case self::PAYMENT_ACTION_ORDER:
                $paymentAction = AbstractMethod::ACTION_ORDER;
                break;
            default:
                $this->_logger->debug($pre . $action . " could not be classified.");
        }

        $this->_logger->debug($pre . 'eof : paymentAction is ' . $paymentAction);

        return $paymentAction;
    }

    /**
     * Check whether specified currency code is supported
     *
     * @param string $code
     *
     * @return bool
     */
    public function isCurrencyCodeSupported($code)
    {
        $supported = false;
        $pre       = __METHOD__ . ' : ';

        $this->_logger->debug($pre . "bof and code: {$code}");

        if (in_array($code, $this->_supportedCurrencyCodes)) {
            $supported = true;
        }

        $this->_logger->debug($pre . "eof and supported : {$supported}");

        return $supported;
    }

    /**
     * Get Config
     *
     * @param mixed $field
     *
     * @return mixed
     */
    public function getConfig(mixed $field)
    {
        $storeScope = ScopeInterface::SCOPE_STORE;
        $path       = 'payment/' . Config::METHOD_CODE . '/' . $field;

        return $this->scopeConfig->getValue($path, $storeScope);
    }

    public function setConfig(string $key, string $value)
    {
        $path = 'payment/' . Config::METHOD_CODE . '/' . $key;

        $this->configWriter->save($path, $value);
    }

    /**
     * Check isVault condition
     **/
    public function isVault()
    {
        return $this->getConfig('fortis_cc_vault_active');
    }

    /**
     * @return bool
     */
    public function isCheckoutIframe(): bool
    {
        return $this->getConfig('fortis_checkout_iframe_enabled') === 'iframe';
    }

    /**
     * @return bool
     */
    public function isSingleView(): bool
    {
        return $this->getConfig('fortis_single_view') === 'single';
    }

    /**
     * Returns true if ACH is configured active in store settings
     *
     * @return bool
     */
    public function achIsActive(): bool
    {
        $r = $this->getConfig('fortis_ach_active');

        return $r === '1';
    }

    /**
     * ACH product ID (optional)
     *
     * @return string
     */
    public function achProductId(): string
    {
        $t = $this->getConfig('fortis_ach_product_id') ?? '';
        if ($t !== '') {
            $t = $this->encryptor->decrypt($t);
        }

        return $t;
    }

    public function achLocationId(): string
    {
        $t = $this->getConfig('fortis_ach_location_id') ?? '';
        if ($t !== '') {
            $t = $this->encryptor->decrypt($t);
        }

        return $t;
    }

    /**
     * @return string
     */
    public function achWebhookId(): string
    {
        return $this->getConfig('fortis_ach_webhook_id') ?? '';
    }

    /**
     * @return array
     */
    public function getACHIcon(): array
    {
        return self::ACH_ICON;
    }

    /**
     * CC product ID (optional)
     *
     * @return string
     */
    public function ccProductId(): string
    {
        if ($this->achIsActive()) {
            $t = $this->getConfig('fortis_ach_cc_product_id') ?? '';
        } else {
            $t = $this->getConfig('product_transaction_id') ?? '';
        }

        if ($t !== '') {
            $t = $this->encryptor->decrypt($t);
        }

        return $t;
    }

    /**
     * Return true if vaulting is enabled for store
     *
     * @return bool
     */
    public function saveAccount(): bool
    {
        return (int)$this->getConfig('fortis_cc_vault_active') === 1;
    }

    /**
     * Get Api Credential for Fortis Payment
     **/
    public function getApiCredentials(): array
    {
        // TODO: Update for Fortis
        $data                 = [];
        $data['user_api_key'] = $this->getConfig('user_api_key');
        $data['user_id']      = $this->getConfig('user_id');

        return $data;
    }

    /**
     * Check whether specified locale code is supported. Fallback to en_US
     *
     * @param string|null $localeCode
     *
     * @return string
     */
    protected function _getSupportedLocaleCode($localeCode = null)
    {
        if (!$localeCode || !in_array($localeCode, $this->_supportedImageLocales)) {
            return 'en_US';
        }

        return $localeCode;
    }

    /**
     * Map config fields
     *
     * @param string $fieldName
     *
     * @return string|null
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _mapFortisFieldset($fieldName)
    {
        return "payment/{$this->_methodCode}/{$fieldName}";
    }

    /**
     * Map any supported payment method into a config path by specified field name
     *
     * @param string $fieldName
     *
     * @return string|null
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _getSpecificConfigPath($fieldName)
    {
        return $this->_mapFortisFieldset($fieldName);
    }

    /**
     * Decrypted user id
     *
     * @return string
     */
    public function userId(): string
    {
        $t = $this->getConfig('user_id') ?? '';
        if ($t !== '') {
            $t = $this->encryptor->decrypt($t);
        }

        return $t;
    }

    /**
     * Decrypted user api key
     *
     * @return string
     */
    public function userApiKey(): string
    {
        $t = $this->getConfig('user_api_key') ?? '';
        if ($t !== '') {
            $t = $this->encryptor->decrypt($t);
        }

        return $t;
    }

    /**
     * @return string
     */
    public function orderAction(): string
    {
        return $this->getConfig('order_intention');
    }

    /**
     * @return bool
     */
    public function orderSuccessfulEmail(): bool
    {
        $t = $this->getConfig('order_email');

        return $t !== '0';
    }

    /**
     * @return bool
     */
    public function emailInvoice(): bool
    {
        $t = $this->getConfig('invoice_email');

        return $t !== '0';
    }
}
