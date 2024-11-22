<?php

namespace Fortispay\Fortis\Model;

use Magento\Directory\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Model\MethodInterface;
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
class Config
{
    public const METHOD_CODE = 'fortis';

    public const PAYMENT_ACTION_SALE = 'Sale';

    public const PAYMENT_ACTION_AUTH = 'Authorization';

    public const PAYMENT_ACTION_ORDER = 'Order';

    public const ACH_ICON = [
        'width'  => 50,
        'height' => 33
    ];

    /**
     * @var Data
     */
    private Data $directoryHelper;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * Currency codes supported by Fortis methods
     * @var string[]
     */
    private array $supportedCurrencyCodes = ['USD', 'EUR', 'GPD', 'ZAR'];

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var Repository
     */
    private Repository $assetRepo;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;
    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;
    /**
     * @var WriterInterface
     */
    private WriterInterface $configWriter;
    /**
     * Current payment method code
     *
     * @var string
     */
    private string $methodCode;
    /**
     * Current store id
     *
     * @var int
     */
    private int $storeId;

    /**
     * Construct
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param Data $directoryHelper
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param Repository $assetRepo
     * @param EncryptorInterface $encryptor
     * @param WriterInterface $configWriter
     *
     * @throws NoSuchEntityException
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Data $directoryHelper,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        Repository $assetRepo,
        EncryptorInterface $encryptor,
        WriterInterface $configWriter,
    ) {
        $this->logger          = $logger;
        $this->directoryHelper = $directoryHelper;
        $this->storeManager    = $storeManager;
        $this->assetRepo       = $assetRepo;
        $this->scopeConfig     = $scopeConfig;
        $this->setMethod(self::METHOD_CODE);
        $currentStoreId = $this->storeManager->getStore()->getStoreId();
        $this->setStoreId($currentStoreId);
        $this->encryptor    = $encryptor;
        $this->configWriter = $configWriter;
    }

    /**
     * @return string
     */
    public function environment(): ?string
    {
        return $this->getConfig('fortis_environment');
    }

    /**
     * Return merchant country code, use default country if it not specified in General settings
     *
     * @return string|null
     */
    public function getMerchantCountry()
    {
        return $this->directoryHelper->getDefaultCountry($this->storeId);
    }

    /**
     * Is Method Supported For Country
     *
     * Check whether method supported for specified country or not
     * Use $methodCode and merchant country by default
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
            return $this->assetRepo->getUrl('Fortispay_Fortis::images/logo-ach.png');
        }

        return $this->assetRepo->getUrl('Fortispay_Fortis::images/logo.png');
    }

    /**
     * Get Fortis icon image URL
     *
     * @return string
     */
    public function getFortisIconImageUrl()
    {
        return $this->assetRepo->getUrl('Fortispay_Fortis::images/fortis_ach.png');
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
        $paymentAction = null;
        $pre           = __METHOD__ . ' : ';
        $this->logger->debug($pre . 'bof');

        $action = $this->getConfig('paymentAction');

        switch ($action) {
            case self::PAYMENT_ACTION_AUTH:
                $paymentAction = MethodInterface::ACTION_AUTHORIZE;
                break;
            case self::PAYMENT_ACTION_SALE:
                $paymentAction = MethodInterface::ACTION_AUTHORIZE_CAPTURE;
                break;
            case self::PAYMENT_ACTION_ORDER:
                $paymentAction = MethodInterface::ACTION_ORDER;
                break;
            default:
                $this->logger->debug($pre . $action . " could not be classified.");
        }

        $this->logger->debug($pre . 'eof : paymentAction is ' . $paymentAction);

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

        $this->logger->debug($pre . "bof and code: {$code}");

        if (in_array($code, $this->supportedCurrencyCodes)) {
            $supported = true;
        }

        $this->logger->debug($pre . "eof and supported : {$supported}");

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
     * Returns true if Google Pay is configured active in store settings
     *
     * @return bool
     */
    public function googlePayIsActive(): bool
    {
        $r = $this->getConfig('fortis_googlepay_active');

        return $r === '1';
    }

    /**
     * Returns true if Apple Pay is configured active in store settings
     *
     * @return bool
     */
    public function applePayIsActive(): bool
    {
        $r = $this->getConfig('fortis_applepay_active');

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
    public function orderAction(): ?string
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

    /**
     * Return Place Order button text
     *
     * @return string
     */
    public function getPlaceOrderBtnText(): string
    {
        return $this->getConfig('fortis_place_order_btn') ?? 'Place order';
    }

    /**
     * Return Place Order button text
     *
     * @return string
     */
    public function getCancelOrderBtnText(): string
    {
        return $this->getConfig('fortis_cancel_order_btn') ?? 'Place order';
    }

    /**
     * Method code setter
     *
     * @param string|MethodInterface $method
     *
     * @return $this
     */
    public function setMethod($method)
    {
        if ($method instanceof MethodInterface) {
            $this->methodCode = $method->getCode();
        } elseif (is_string($method)) {
            $this->methodCode = $method;
        }

        return $this;
    }

    /**
     * Payment method instance code getter
     *
     * @return string
     */
    public function getMethodCode()
    {
        return $this->methodCode;
    }

    /**
     * Store ID setter
     *
     * @param int $storeId
     *
     * @return $this
     */
    public function setStoreId($storeId)
    {
        $this->storeId = (int)$storeId;

        return $this;
    }

    /**
     * Store ID Getter
     *
     * @return int
     */
    public function getStoreId()
    {
        return $this->storeId;
    }

    /**
     * Check whether method available for checkout or not
     *
     * @param string|null $methodCode
     *
     * @return bool
     */
    public function isMethodAvailable($methodCode = null)
    {
        $methodCode = $methodCode ?: $this->methodCode;

        return $this->isMethodActive($methodCode);
    }

    /**
     * Check whether method active in configuration and supported for merchant country or not
     *
     * @param string $method Method code
     *
     * @return bool
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function isMethodActive(string $method): bool
    {
        $isEnabled = $this->scopeConfig->isSetFlag(
            "payment/{$method}/active",
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );

        return $this->isMethodSupportedForCountry($method) && $isEnabled;
    }
}
