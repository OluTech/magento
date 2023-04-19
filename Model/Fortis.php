<?php

namespace Fortis\Fortis\Model;

use Fortis\Fortis\CountryData;
use Fortis\Fortis\Helper\Data;
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
use Magento\Vault\Model\CreditCardTokenFactory;
use Magento\Vault\Model\ResourceModel\PaymentToken as PaymentTokenResourceModel;
use SimpleXMLElement;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Fortis extends AbstractMethod
{
    const        SECURE = ['_secure' => true];
    public const FFFFFF = '#ffffff';
    /**
     * @var string
     */
    protected $_code = Config::METHOD_CODE;
    /**
     * @var string
     */
    protected $_formBlockType = 'Fortis\Fortis\Block\Form';
    /**
     * @var string
     */
    protected $_infoBlockType = 'Fortis\Fortis\Block\Payment\Info';
    /**
     * @var string
     */
    protected $_configType = 'Fortis\Fortis\Model\Config';
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
    protected $creditCardTokenFactory;
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
    protected $_canRefund               = true;
    protected $_canRefundInvoicePartial = true;
    /**
     * @var PaymentTokenResourceModel
     */
    protected $paymentTokenResourceModel;
    protected $transactions;
    /**
     * \Magento\Payment\Helper\Data $paymentData,
     */
    protected $_paymentData;

    /**
     * @var OrderRepositoryInterface $orderRepository
     */
    protected $orderRepository;

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

    public static array $encryptedConfigKeys = [
        'user_id',
        'user_api_key',
    ];

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param ConfigFactory $configFactory
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param FormKey $formKey
     * @param CartFactory $cartFactory
     * @param Session $checkoutSession
     * @param LocalizedExceptionFactory $exception
     * @param TransactionRepositoryInterface $transactionRepository
     * @param BuilderInterface $transactionBuilder
     * @param AbstractResource $resource
     * @param AbstractDb $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
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
        ConfigFactory $configFactory,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        FormKey $formKey,
        Session $checkoutSession,
        LocalizedExceptionFactory $exception,
        TransactionRepositoryInterface $transactionRepository,
        BuilderInterface $transactionBuilder,
        CreditCardTokenFactory $CreditCardTokenFactory,
        PaymentTokenRepositoryInterface $PaymentTokenRepositoryInterface,
        PaymentTokenManagementInterface $paymentTokenManagement,
        EncryptorInterface $encryptor,
        PaymentTokenResourceModel $paymentTokenResourceModel,
        TransactionSearchResultInterfaceFactory $transactions,
        OrderRepositoryInterface $orderRepository,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ){
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
        $this->creditCardTokenFactory    = $CreditCardTokenFactory;
        $this->paymentTokenRepository    = $PaymentTokenRepositoryInterface;
        $this->paymentTokenManagement    = $paymentTokenManagement;
        $this->encryptor                 = $encryptor;
        $this->paymentTokenResourceModel = $paymentTokenResourceModel;
        $this->transactions              = $transactions;
        $this->_paymentData              = $fortisData;
        $this->orderRepository           = $orderRepository;

        $parameters = ['params' => [$this->_code]];

        $this->_config = $configFactory->create($parameters);
    }

    public function pay($invoice)
    {
    }

    /**
     * Store setter
     * Also updates store ID in config object
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
     * @return array
     */
    public function getFortisOrderToken(bool $saveAccount)
    {
        // Variable initialization
        $saveAccount          = $saveAccount && ((int)$this->getConfigData('fortis_cc_vault_active') === 1);
        $productTransactionId = $this->encryptor->decrypt($this->getConfigData('product_transaction_id'));

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

        // Initiate Fortis - transaction intention
        $api                    = new FortisApi($this->getConfigData('fortis_environment'));
        $client_token           = $api->getClientToken($intentData, $user_id, $user_api_key);
        $body                   = json_decode($api->getTokenBody($client_token));
        $product_transaction_id = $body->transaction->methods[0]->product_transaction_id;
        $order->addCommentToStatusHistory("product_transaction_id:$product_transaction_id");
        $order->save();

        return [
            'token'       => $client_token,
            'options'     => $options,
            'redirectUrl' => $returnUrl,
        ];
    }

    /**
     * @return array
     */
    public function getFortrisCredentials(): array
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
     * @return array
     */
    public function prepareFields(Order $order): array
    {
        $pre = __METHOD__ . ' : ';

        $order->getPayment()->getData();

        $this->_logger->debug($pre . 'serverMode : ' . $this->getConfigData('test_mode'));

        $cred = $this->getFortrisCredentials();

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
                'colorButtonSelectedText'       => isset($cred['fortis_color_button_selected_text']) ? $cred['fortis_color_button_selected_text'] : self::FFFFFF,
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

        $action = $this->getConfigData('order_intention');
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

    public function getOrderPlaceRedirectUrl()
    {
        return $this->getCheckoutRedirectUrl();
    }

    /**
     * Checkout redirect URL getter for onepage checkout (hardcode)
     *
     * @return string
     * @see Quote\Payment::getCheckoutRedirectUrl()
     * @see \Magento\Checkout\Controller\Onepage::savePaymentAction()
     */
    public function getCheckoutRedirectUrl()
    {
        return $this->_urlBuilder->getUrl('fortis/redirect');
    }

    /**
     *
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return $this
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

    public function getCountryDetails($code2)
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
     * @return $this
     * @throws LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $order         = $payment->getOrder();
        $transactionId = $payment->getLastTransId();
        $intentData    = [
            'transaction_amount' => (int)($amount * 100),
            'transactionId'      => $transactionId,
        ];

        $user_id      = $this->getConfigData('user_id');
        $user_api_key = $this->getConfigData('user_api_key');
        $api          = new FortisApi($this->getConfigData('fortis_environment'));
        try {
            $response = $api->refundTransactionAmount($intentData, $user_id, $user_api_key);
            $data     = json_decode($response)->data;

            $helper = $this->_paymentData;
            $amount = $helper->convertToOrderCurrency($order, $amount);

            /* Set Comment to Order*/
            if ($data->reason_code_id === 1000) {
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
        } catch (\Exception $exception) {
            $order->addStatusHistoryComment(__("Refund not successful"))->save();

            return false;
        }
    }

    public function getOrderbyOrderId($order_id)
    {
        return $this->orderRepository->get($order_id);
    }

    /**
     * @inheritdoc
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId)
    {
        $state = ObjectManager::getInstance()->get('\Magento\Framework\App\State');
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

    public function getStandardCheckoutFormFields()
    {
        return 'TODO';
    }

    /**
     * @return \Magento\Vault\Api\PaymentTokenManagementInterface
     */
    public function getPaymentTokenManagement(): PaymentTokenManagementInterface
    {
        return $this->paymentTokenManagement;
    }

    /**
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
     * @param $order
     * @param $data
     *
     * @return void
     */
    public function saveVaultData($order, $data)
    {
        if (
            ((int)($this->getConfigData('fortis_cc_vault_active') ?? 0) !== 1) ||
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
            $paymentToken = $this->creditCardTokenFactory->create();
        }

        $paymentToken->setGatewayToken($data->saved_account->id);
        $expDate = $data->saved_account->exp_date;
        $month   = substr($expDate, 0, 2);
        $year    = substr($expDate, 2, 2);
        $paymentToken->setTokenDetails(
            json_encode(
                [
                    'type'           => $data->saved_account->payment_method,
                    'maskedCC'       => $data->saved_account->last_four,
                    'expirationDate' => "$month/$year",
                ]
            )
        );
        $paymentToken->setExpiresAt($this->getExpirationDate($month, $year));
        $paymentToken->setIsActive((int)$data->saved_account->active === 1);
        $paymentToken->setIsVisible(true);
        $paymentToken->setPaymentMethodCode('fortis');
        $paymentToken->setCustomerId($order->getCustomerId());
        $paymentToken->setPublicHash($this->generatePublicHash($paymentToken));

        $this->paymentTokenRepository->save($paymentToken);

        /* Retrieve Payment Token */

        $this->creditCardTokenFactory->create();
        $this->addLinkToOrderPayment($paymentToken->getEntityId(), $order->getPayment()->getEntityId());
    }

    /**
     * @param Payment $payment
     *
     * @return string
     */
    private function getExpirationDate($month, $year)
    {
        $expDate = new \DateTime(
            $year
            . '-'
            . $month
            . '-'
            . '01'
            . ' '
            . '00:00:00',
            new \DateTimeZone('UTC')
        );
        $expDate->add(new \DateInterval('P1M'));

        return $expDate->format('Y-m-d 00:00:00');
    }

    /**
     * Generate vault payment public hash
     *
     * @param $paymentToken
     *
     * @return string
     */
    protected function generatePublicHash($paymentToken)
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
}
