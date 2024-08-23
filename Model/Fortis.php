<?php

namespace Fortispay\Fortis\Model;

use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use Fortispay\Fortis\CountryData;
use Fortispay\Fortis\Helper\Data;
use Magento\Checkout\Model\Session;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\LocalizedExceptionFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Model\PaymentTokenFactory;
use Magento\Vault\Model\ResourceModel\PaymentToken as PaymentTokenResourceModel;

use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Io\File;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Fortis extends AbstractMethod
{
    public const        SECURE             = ['_secure' => true];
    public const        FFFFFF             = '#ffffff';
    public const        AVAILABLE_CC_TYPES = [
        'visa' => 'VI',
        'mc'   => 'MC',
        'disc' => 'DI',
        'amex' => 'AE'
    ];
    /**
     * @var array|string[]
     */
    public static array $configKeys = [
        'active',
        'title',
        'test_mode',
        'user_id',
        'user_api_key',
        'order_intention',
        'allowed_carrier',
        'allowspecific',
        'specificcountry',
        'instructions',
        'order_email',
        'invoice_email',
        'sort_order',
        'SuccessFul_Order_status',
        'fortis_theme',
        'fortis_environment',
        'fortis_floating_labels',
        'fortis_validation_animation',
        'fortis_color_button_selected_background',
        'fortis_color_button_selected_text',
        'fortis_color_button_action_background',
        'fortis_color_button_action_text',
        'fortis_color_button_background',
        'fortis_color_button_text',
        'fortis_color_field_background',
        'fortis_color_field_border',
        'fortis_color_text',
        'fortis_color_link',
        'fortis_font_size',
        'fortis_margin_spacing',
        'fortis_border_radius',
    ];

    /**
     * @var array|string[]
     */
    public static array $encryptedConfigKeys = [
        'user_id',
        'user_api_key',
    ];

    /**
     * @var string
     */
    protected $_code = Config::METHOD_CODE;

    /**
     * @var string
     */
    protected $_formBlockType = \Fortispay\Fortis\Block\Form::class;

    /**
     * @var string
     */
    protected $_infoBlockType = \Fortispay\Fortis\Block\Payment\Info::class;

    /**
     * @var string
     */
    protected $_configType = Fortispay\Fortis\Model\Config::class;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isGateway = false;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canOrder = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canAuthorize = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canVoid = false;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseInternal = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseCheckout = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canFetchTransactionInfo = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canReviewPayment = true;

    /**
     * Website Payments Pro instance
     *
     * @var Config $config
     */
    protected $_config;

    /**
     * Payment additional information key for payment action
     *
     * @var string
     */
    protected $_isOrderPaymentActionKey = 'is_order_action';

    /**
     * Payment additional information key for number of used authorizations
     *
     * @var string
     */
    protected $_authorizationCountKey = 'authorization_count';

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var UrlInterface
     */
    protected $_urlBuilder;

    /**
     * @var UrlInterface
     */
    protected $_formKey;

    /**
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * @var LocalizedExceptionFactory
     */
    protected $_exception;

    /**
     * @var TransactionRepositoryInterface
     */
    protected $transactionRepository;

    /**
     * @var BuilderInterface
     */
    protected $transactionBuilder;

    /**
     * @var PaymentTokenRepositoryInterface
     */
    protected $paymentTokenRepository;

    /**
     * @var PaymentTokenManagementInterface
     */
    protected PaymentTokenManagementInterface $paymentTokenManagement;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var Payment
     */
    protected $payment;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @var PaymentTokenResourceModel
     */
    protected $paymentTokenResourceModel;

    /**
     * @var TransactionSearchResultInterfaceFactory
     */
    protected $transactions;

    /**
     * @var Data
     */
    protected $_paymentData;

    /**
     * @var OrderRepositoryInterface $orderRepository
     */
    protected $orderRepository;

    /**
     * @var PaymentTokenFactory
     */
    protected PaymentTokenFactory $paymentTokenFactory;
    protected $directoryList;
    protected $fileIo;
    protected $logger;

    /**
     * Construct
     *
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param Data $fortisData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param ConfigFactory $configFactory
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param FormKey $formKey
     * @param Session $checkoutSession
     * @param LocalizedExceptionFactory $exception
     * @param TransactionRepositoryInterface $transactionRepository
     * @param BuilderInterface $transactionBuilder
     * @param PaymentTokenRepositoryInterface $PaymentTokenRepositoryInterface
     * @param PaymentTokenManagementInterface $paymentTokenManagement
     * @param PaymentTokenFactory $paymentTokenFactory
     * @param EncryptorInterface $encryptor
     * @param PaymentTokenResourceModel $paymentTokenResourceModel
     * @param TransactionSearchResultInterfaceFactory $transactions
     * @param OrderRepositoryInterface $orderRepository
     * @param Config $config
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        Data $fortisData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        DirectoryList $directoryList,
        File $fileIo,
        ConfigFactory $configFactory,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        FormKey $formKey,
        Session $checkoutSession,
        LocalizedExceptionFactory $exception,
        TransactionRepositoryInterface $transactionRepository,
        BuilderInterface $transactionBuilder,
        PaymentTokenRepositoryInterface $PaymentTokenRepositoryInterface,
        PaymentTokenManagementInterface $paymentTokenManagement,
        PaymentTokenFactory $paymentTokenFactory,
        EncryptorInterface $encryptor,
        PaymentTokenResourceModel $paymentTokenResourceModel,
        TransactionSearchResultInterfaceFactory $transactions,
        OrderRepositoryInterface $orderRepository,
        Config $config,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_storeManager             = $storeManager;
        $this->_urlBuilder               = $urlBuilder;
        $this->_formKey                  = $formKey;
        $this->_checkoutSession          = $checkoutSession;
        $this->_exception                = $exception;
        $this->transactionRepository     = $transactionRepository;
        $this->transactionBuilder        = $transactionBuilder;
        $this->paymentTokenFactory       = $paymentTokenFactory;
        $this->paymentTokenRepository    = $PaymentTokenRepositoryInterface;
        $this->paymentTokenManagement    = $paymentTokenManagement;
        $this->encryptor                 = $encryptor;
        $this->paymentTokenResourceModel = $paymentTokenResourceModel;
        $this->transactions              = $transactions;
        $this->_paymentData              = $fortisData;
        $this->orderRepository           = $orderRepository;
        $this->config                    = $config;
        $this->scopeConfig               = $scopeConfig;

        $parameters = ['params' => [$this->_code]];

        $this->_config = $configFactory->create($parameters);

        $this->directoryList = $directoryList;
        $this->fileIo        = $fileIo;

        $this->checkApplePayFile();
    }

    public function checkApplePayFile()
    {
        try {
            if ($this->_config->applePayIsActive()) {
                $currentFolder = $this->directoryList->getRoot();
                $file          = 'apple-developer-merchantid-domain-association';
                $source        = $currentFolder . '/app/code/Fortispay/Fortis/' . $file;
                $targetDir     = $currentFolder . '/pub/.well-known/';
                $target        = $targetDir . $file;

                // Ensure the .well-known directory exists
                if (!is_dir($targetDir)) {
                    $this->fileIo->mkdir($targetDir, 0755);
                }

                if ($this->fileIo->fileExists($source) && !$this->fileIo->fileExists($target)) {
                    $this->fileIo->cp($source, $target);
                }
            }
        } catch (Exception $e) {
            $this->_logger->error('Error copying Apple Pay file: ' . $e->getMessage());
        }
    }

    /**
     * Store setter; also updates store ID in config object
     *
     * @param Store|int $store
     *
     * @return $this
     */
    public function setStore($store)
    {
        $this->setData('store', $store);

        if (null === $store) {
            $store = $this->_storeManager->getStore()->getId();
        }
        $this->_config->setStoreId(is_object($store) ? $store->getId() : $store);

        return $this;
    }

    /**
     * Whether method is available for specified currency
     *
     * @param string $currencyCode
     *
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        return $this->_config->isCurrencyCodeSupported($currencyCode);
    }

    /**
     * Payment action getter compatible with payment model
     *
     * @return string
     * @see \Magento\Sales\Model\Payment::place()
     */
    public function getConfigPaymentAction()
    {
        return $this->_config->getPaymentAction();
    }

    /**
     * Check whether payment method can be used
     *
     * @param CartInterface|Quote|null $quote
     *
     * @return bool
     */
    public function isAvailable(CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->_config->isMethodAvailable();
    }

    /**
     * This is where we compile data posted by the form
     *
     * @param bool $saveAccount
     *
     * @return array
     * @throws Exception
     */
    public function getFortisOrderToken(bool $saveAccount)
    {
        // Variable initialization
        $saveAccount          = $saveAccount && $this->_config->saveAccount();
        $productTransactionId = $this->_config->ccProductId();
        $achEnabled           = $this->_config->achIsActive();
        $achProductId         = $this->_config->achProductId();

        $order      = $this->_checkoutSession->getLastRealOrder();
        $orderTotal = (int)($order->getTotalDue() * 100);
        $orderTax   = (int)($order->getTaxAmount() * 100);
        list($user_id, $user_api_key, $action, $options, $returnUrl) = $this->prepareFields(
            $order
        );
        $intentData = [
            'action'       => $action,
            'amount'       => $orderTotal,
            'save_account' => $saveAccount,
        ];
        if ($orderTax > 0) {
            $intentData['tax_amount'] = $orderTax;
        }
        if ($productTransactionId
            && preg_match(
                '/^(([0-9a-fA-F]{24})|(([0-9a-fA-F]{8})(([0-9a-fA-F]{4}){3})([0-9a-fA-F]{12})))$/',
                $productTransactionId
            ) === 1) {
            $intentData['methods']   = [];
            $intentData['methods'][] = ['type' => 'cc', 'product_transaction_id' => $productTransactionId];
        }
        if ($achEnabled && $achProductId
            && preg_match(
                '/^(([0-9a-fA-F]{24})|(([0-9a-fA-F]{8})(([0-9a-fA-F]{4}){3})([0-9a-fA-F]{12})))$/',
                $achProductId
            ) === 1) {
            if (empty($intentData['methods'])) {
                $intentData['methods'] = [];
            }
            $intentData['methods'][] = ['type' => 'ach', 'product_transaction_id' => $achProductId];
        }

        // Initiate Fortis - transaction intention
        $api                    = new FortisApi($this->config);
        $client_token           = $api->getClientToken($intentData, $user_id, $user_api_key);
        $body                   = json_decode($api->getTokenBody($client_token));
        $product_transaction_id = $body->transaction->methods[0]->product_transaction_id;
        $order->addCommentToStatusHistory("product_transaction_id:$product_transaction_id");
        $order->save();

        return [
            'token'       => $client_token,
            'options'     => $options,
            'redirectUrl' => $returnUrl,
            'googlepay'   => $this->_config->googlePayIsActive(),
            'applepay'    => $this->_config->applePayIsActive(),
        ];
    }

    /**
     * Get Fortis Credentials
     *
     * @return array
     */
    public function getFortisCredentials(): array
    {
        $creds = [];
        foreach (self::$configKeys as $key) {
            $creds[$key] = $this->getConfigData($key);

            if (in_array($key, self::$encryptedConfigKeys)) {
                $creds[$key] = $this->encryptor->decrypt($creds[$key]);
            }
        }

        return $creds;
    }

    /**
     * Prepare Fields
     *
     * @param Order $order
     *
     * @return array
     */
    public function prepareFields(Order $order): array
    {
        $pre = __METHOD__ . ' : ';

        $order->getPayment()->getData();

        $this->_logger->debug($pre . 'serverMode : ' . $this->getConfigData('test_mode'));

        $cred = $this->getFortisCredentials();

        $user_id      = $cred['user_id'];
        $user_api_key = $cred['user_api_key'];
        $action       = $cred['order_intention'] ?? 'sale';

        $options = [
            'main_options'       => [
                'theme'                   => $cred['fortis_theme'] ?? 'default',
                'environment'             => $cred['fortis_environment'] ?? 'production',
                'floatingLabels'          => $cred['fortis_floating_labels'] ?? 0,
                'showValidationAnimation' => $cred['fortis_validation_animation'] ?? 0,
            ],
            'appearance_options' => [
                'colorButtonSelectedBackground' => $cred['fortis_color_button_selected_background'] ?? '#363636',
                'colorButtonSelectedText'       => $cred['fortis_color_button_selected_text'] ?? self::FFFFFF,
                'colorButtonActionBackground'   => $cred['fortis_color_button_action_background'] ?? '#00d1b2',
                'colorButtonActionText'         => $cred['fortis_color_button_action_text'] ?? self::FFFFFF,
                'colorButtonBackground'         => $cred['fortis_color_button_background'] ?? self::FFFFFF,
                'colorButtonText'               => $cred['fortis_color_button_text'] ?? '#363636',
                'colorFieldBackground'          => $cred['fortis_color_field_background'] ?? self::FFFFFF,
                'colorFieldBorder'              => $cred['fortis_color_field_border'] ?? '#dbdbdb',
                'colorText'                     => $cred['fortis_color_text'] ?? '#4a4a4a',
                'colorLink'                     => $cred['fortis_color_link'] ?? '#485fc7',
                'fontSize'                      => ($cred['fortis_font_size'] ?? '16') . 'px',
                'marginSpacing'                 => ($cred['fortis_margin_spacing'] ?? '0.5') . 'rem',
                'borderRadius'                  => ($cred['fortis_border_radius'] ?? 4) . 'px',
            ],
        ];

        $billing = $order->getBillingAddress();
        $billing->getCountryId();

        $action = $this->config->orderAction();
        if ($action === 'sale') {
            $returnUrl = $this->_urlBuilder->getUrl(
                'fortis/redirect/success',
                self::SECURE
            ) . '?gid=' . $order->getRealOrderId();
        } else {
            $returnUrl = $this->_urlBuilder->getUrl(
                'fortis/redirect/authorise',
                self::SECURE
            ) . '?gid=' . $order->getRealOrderId();
        }

        return [$user_id, $user_api_key, $action, $options, $returnUrl];
    }

    /**
     * Get Order Place Redirect Url
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return $this->getCheckoutRedirectUrl();
    }

    /**
     * Checkout redirect URL getter for onepage checkout (hardcode)
     *
     * @return string
     */
    public function getCheckoutRedirectUrl()
    {
        return $this->_urlBuilder->getUrl('fortis/redirect');
    }

    /**
     * Initialize
     *
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return Fortis
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        return parent::initialize($paymentAction, $stateObject);
    }

    /*
     * called dynamically by checkout's framework.
     */

    /**
     * Add link between payment token and order payment.
     *
     * @param int $paymentTokenId Payment token ID.
     * @param int $orderPaymentId Order payment ID.
     *
     * @return bool
     */
    public function addLinkToOrderPayment($paymentTokenId, $orderPaymentId)
    {
        return $this->paymentTokenResourceModel->addLinkToOrderPayment($paymentTokenId, $orderPaymentId);
    }

    /**
     * Get Country Details
     *
     * @param mixed $code2
     *
     * @return mixed|void
     */
    public function getCountryDetails(mixed $code2)
    {
        $countries = CountryData::getCountries();

        foreach ($countries as $key => $val) {
            if ($key == $code2) {
                return $val[2];
            }
        }
    }

    /**
     * Check refund availability.
     * The main factor is that the last capture transaction exists and has an Payflow\Pro::TRANSPORT_PAYFLOW_TXN_ID in
     * additional information(needed to perform online refund. Requirement of the Payflow gateway)
     *
     * @return bool
     */
    public function canRefund()
    {
        /** @var Payment $paymentInstance */
        $paymentInstance = $this->getInfoInstance();
        // we need the last capture transaction was made
        $captureTransaction = $this->transactionRepository->getByTransactionType(
            Transaction::TYPE_CAPTURE,
            $paymentInstance->getId(),
            $paymentInstance->getOrder()->getId()
        );

        return $captureTransaction && $captureTransaction->getTransactionId() && $this->_canRefund;
    }

    /**
     * Check partial refund availability for invoice
     *
     * @return bool
     * @api
     */
    public function canRefundPartialPerInvoice()
    {
        return $this->canRefund();
    }

    /**
     * Refund specified amount for payment
     *
     * @param DataObject|InfoInterface $payment
     * @param float $amount
     *
     * @return bool
     * @throws LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();

        if ($order->getStatus() !== Order::STATE_PROCESSING) {
            $order->setState(Order::STATE_PROCESSING);
        }

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));
        $api          = new FortisApi($this->config);
        $type         = $this->scopeConfig->getValue('payment/fortis/order_intention');

        $transactionId = $payment->getLastTransId();
        $intentData    = [
            'transaction_amount' => (int)($amount * 100),
            'transactionId'      => $transactionId,
            'description'        => $order->getIncrementId()
        ];

        $paymentMethod         = 'cc';
        $additionalInformation = $payment->getAdditionalInformation();
        $rawDetailsInfo        = null;
        if (!empty($additionalInformation) && !empty($additionalInformation['raw_details_info'])) {
            $rawDetailsInfo = json_decode($additionalInformation['raw_details_info']);
            $paymentMethod  = $rawDetailsInfo->payment_method;
        }

        try {
            if ($type === 'auth-only') {
                $response = $api->refundAuthAmount($intentData, $user_id, $user_api_key);
            } else {
                if ($paymentMethod !== 'ach') {
                    $response = $api->refundTransactionAmount($intentData, $user_id, $user_api_key);
                } else {
                    $intentData = [
                        'transaction_amount'      => $intentData['transaction_amount'],
                        'description'             => $order->getIncrementId(),
                        'previous_transaction_id' => $rawDetailsInfo?->id,
                    ];
                    $response   = $api->achRefundTransactionAmount($intentData);
                }
            }
            $data = json_decode($response)->data ?? null;

            /* Set Comment to Order*/
            if ($data?->reason_code_id === 1000) {
                $order->addStatusHistoryComment(
                    __(
                        "Order Successfully Refunded with Transaction Id - $data->id Auth Code - $data->auth_code"
                    )
                )->save();

                return true;
            } else {
                $order->addStatusHistoryComment(__("Refund not successful"))->save();

                return false;
            }
        } catch (Exception $exception) {
            $order->addStatusHistoryComment(__("Refund not successful"))->save();

            return false;
        }
    }

    /**
     * Get Order by Order Id
     *
     * @param int $order_id
     *
     * @return \Magento\Sales\Api\Data\OrderInterface
     */
    public function getOrderbyOrderId(int $order_id)
    {
        return $this->orderRepository->get($order_id);
    }

    /**
     * Fetch Transaction Info
     *
     * @param InfoInterface $payment
     * @param int $transactionId
     *
     * @return array|mixed
     * @throws LocalizedException
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId)
    {
        $state = ObjectManager::getInstance()->get(\Magento\Framework\App\State::class);
        if ($state->getAreaCode() == Area::AREA_ADMINHTML) {
            $order_id = $payment->getOrder()->getId();
            $order    = $this->getOrderbyOrderId($order_id);

            $result = $this->_paymentData->getQueryResult($transactionId);

            if (isset($result['ns2PaymentType'])) {
                $result['PAYMENT_TYPE_METHOD'] = $result['ns2PaymentType']['ns2Method'];
                $result['PAYMENT_TYPE_DETAIL'] = $result['ns2PaymentType']['ns2Detail'];
            }
            unset($result['ns2PaymentType']);

            $result['PAY_REQUEST_ID'] = $transactionId;
            $result['PAYMENT_TITLE']  = "FORTIS_FORTIS";
            $this->_paymentData->updatePaymentStatus($order, $result);
        }

        return $result;
    }

    /**
     * Get Standard Checkout Form Fields
     *
     * @return string
     */
    public function getStandardCheckoutFormFields()
    {
        return 'TODO';
    }

    /**
     * Get Payment Token Management
     *
     * @return PaymentTokenManagementInterface
     */
    public function getPaymentTokenManagement(): PaymentTokenManagementInterface
    {
        return $this->paymentTokenManagement;
    }

    /**
     * Save Vault Data
     *
     * @param object $order
     * @param object $data
     *
     * @return void
     */
    public function saveVaultData(object $order, object $data)
    {
        if (((int)($this->getConfigData('fortis_cc_vault_active') ?? 0) !== 1) ||
            !isset($data->saved_account)
        ) {
            return;
        }

        $customerId = $order->getCustomerId();
        // Check for existing card
        $paymentToken = $this->paymentTokenManagement->getByGatewayToken(
            $data->saved_account->id,
            'fortis',
            $order->getCustomerId()
        );
        if (!$paymentToken) {
            $paymentToken = $this->paymentTokenFactory->create();
        }

        $paymentToken->setPaymentMethodCode(Config::METHOD_CODE);

        $paymentToken->setGatewayToken($data->saved_account->id);
        $expDate = $data->saved_account->exp_date;

        if ($data->saved_account->payment_method === 'ach') {
            $paymentTokenType = PaymentTokenFactoryInterface::TOKEN_TYPE_ACCOUNT;
            $tokenType        = $data->saved_account->payment_method;
        } else {
            $paymentTokenType = PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD;
            $tokenType        = self::AVAILABLE_CC_TYPES[$data->saved_account->account_type]
                                ?? $data->saved_account->account_type;
        }

        $tokenDetails = [
            'type'     => $tokenType,
            'maskedCC' => $data->saved_account->last_four,
        ];

        if (!$expDate) {
            $expDate = $this->createExpiryDate();
        }

        $month                          = substr($expDate, 0, 2);
        $year                           = substr($expDate, 2, 2);
        $tokenDetails['expirationDate'] = "$month/$year";

        $paymentToken->setTokenDetails(json_encode($tokenDetails));

        $paymentToken->setExpiresAt($this->getExpirationDate($month, $year));

        $paymentToken->setIsActive((int)$data->saved_account->active === 1);
        $paymentToken->setIsVisible(true);
        $paymentToken->setType($paymentTokenType);
        $paymentToken->setCustomerId($order->getCustomerId());
        $paymentToken->setPublicHash($this->generatePublicHash($paymentToken));

        $this->paymentTokenRepository->save($paymentToken);

        /* Retrieve Payment Token */

        $this->paymentTokenFactory->create();
        $this->addLinkToOrderPayment($paymentToken->getEntityId(), $order->getPayment()->getEntityId());
    }

    public function createExpiryDate(): string
    {
        $one_year_from_now_timestamp = strtotime('+1 year');

        return date('my', $one_year_from_now_timestamp);
    }

    /**
     * Get Store Name
     *
     * @return mixed
     */
    protected function getStoreName()
    {
        return $this->_scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Place an order with authorization or capture action
     *
     * @param Payment $payment
     * @param float $amount
     *
     * @return $this
     */
    protected function _placeOrder(Payment $payment, $amount)
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
    }

    /**
     * Get transaction with type order
     *
     * @param OrderPaymentInterface $payment
     *
     * @return false|TransactionInterface
     */
    protected function getOrderTransaction($payment)
    {
        return $this->transactionRepository->getByTransactionType(
            Transaction::TYPE_ORDER,
            $payment->getId(),
            $payment->getOrder()->getId()
        );
    }

    /**
     * Generate vault payment public hash
     *
     * @param object $paymentToken
     *
     * @return string
     */
    protected function generatePublicHash(object $paymentToken)
    {
        $hashKey = $paymentToken->getGatewayToken();
        if ($paymentToken->getCustomerId()) {
            $hashKey = $paymentToken->getCustomerId();
        }
        $paymentToken->getTokenDetails();

        $hashKey .= $paymentToken->getPaymentMethodCode() . $paymentToken->getType() . $paymentToken->getGatewayToken(
        ) . $paymentToken->getTokenDetails();

        return $this->encryptor->getHash($hashKey);
    }

    /**
     * Get Expiration Date
     *
     * @param string $month
     * @param string $year
     *
     * @return string
     * @throws Exception
     */
    private function getExpirationDate(string $month, string $year)
    {
        $expDate = new DateTime(
            $year
            . '-'
            . $month
            . '-'
            . '01'
            . ' '
            . '00:00:00',
            new DateTimeZone('UTC')
        );
        $expDate->add(new DateInterval('P1M'));

        return $expDate->format('Y-m-d 00:00:00');
    }
}
