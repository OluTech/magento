<?php

namespace Fortispay\Fortis\Controller;

use Fortispay\Fortis\Helper\Data as FortisHelper;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\Fortis;
use Magento\Checkout\Controller\Express\RedirectLoginInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url;
use Magento\Framework\App\Action\Action as AppAction;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\State;
use Magento\Framework\DB\Transaction as DBTransaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Session\Generic;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Url\Helper\Data;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Checkout Controller
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractFortis extends AppAction implements RedirectLoginInterface, CsrfAwareActionInterface
{
    /**
     * @var array|string[]
     */
    public static array $encryptedConfigKeys = [
        'user_id',
        'user_api_key',
    ];
    /**
     * Internal cache of checkout models
     *
     * @var array
     */
    protected $_checkoutTypes = [];
    /**
     * @var Config
     */
    protected $_config;

    /**
     * @var Quote
     */
    protected $_quote = false;

    /**
     * Config mode type
     *
     * @var string
     */
    protected $_configType = 'Fortispay\Fortis\Model\Config::class';

    /**
     * @var Fortis|string
     */
    protected $_configMethod = Config::METHOD_CODE;

    /**
     * Checkout mode type
     *
     * @var string
     */
    protected $_checkoutType;

    /**
     * @var CustomerSession
     */
    protected $_customerSession;

    /**
     * @var CheckoutSession $_checkoutSession
     */
    protected $_checkoutSession;

    /**
     * @var OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var Generic
     */
    protected $fortisSession;

    /**
     * @var Helper
     */
    protected $_urlHelper;

    /**
     * @var Url
     */
    protected $_customerUrl;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var  Order $_order
     */
    protected $_order;

    /**
     * @var PageFactory
     */
    protected $pageFactory;

    /**
     * @var TransactionFactory
     */
    protected $_transactionFactory;

    /**
     * @var  StoreManagerInterface $storeManager
     */
    protected $_storeManager;

    /**
     * @var Fortis $_paymentMethod
     */
    protected $_paymentMethod;

    /**
     * @var OrderRepositoryInterface $orderRepository
     */
    protected $orderRepository;

    /**
     * @var CollectionFactory $_orderCollectionFactory
     */
    protected $_orderCollectionFactory;

    /**
     * @var Magento\Sales\Model\Order\Payment\Transaction\Builder $_transactionBuilder
     */
    protected $_transactionBuilder;
    /**
     * @var DBTransaction
     */
    protected $dbTransaction;
    /**
     * @var Order
     */
    protected $order;
    /**
     * @var Config
     */
    protected $config;
    /**
     * @var TransactionSearchResultInterfaceFactory
     */
    protected $transactionSearchResultInterfaceFactory;
    /**
     * @var InvoiceService
     */
    protected $_invoiceService;
    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;
    /**
     * @var OrderSender
     */
    protected $OrderSender;
    /** @var State * */
    protected $state;
    /**
     * @var UrlInterface
     */
    private $_urlBuilder;
    /**
     * @var DateTime
     */
    private $_date;
    /** @var State * */
    private $_fortishelper;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var ResultFactory
     */
    protected $resultFactory;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param CustomerSession $customerSession
     * @param CheckoutSession $checkoutSession
     * @param OrderFactory $orderFactory
     * @param Generic $fortisSession
     * @param Data $urlHelper
     * @param Url $customerUrl
     * @param LoggerInterface $logger
     * @param TransactionFactory $transactionFactory
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param Fortis $paymentMethod
     * @param UrlInterface $urlBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param StoreManagerInterface $storeManager
     * @param OrderSender $OrderSender
     * @param DateTime $date
     * @param CollectionFactory $orderCollectionFactory
     * @param Builder $_transactionBuilder
     * @param DBTransaction $dbTransaction
     * @param Order $order
     * @param Config $config
     * @param State $state
     * @param FortisHelper $fortishelper
     * @param TransactionSearchResultInterfaceFactory $transactionSearchResultInterfaceFactory
     * @param EncryptorInterface $encryptor
     * @param RequestInterface $request
     * @param ResultFactory $resultFactory
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        OrderFactory $orderFactory,
        Generic $fortisSession,
        Data $urlHelper,
        Url $customerUrl,
        LoggerInterface $logger,
        TransactionFactory $transactionFactory,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        Fortis $paymentMethod,
        UrlInterface $urlBuilder,
        OrderRepositoryInterface $orderRepository,
        StoreManagerInterface $storeManager,
        OrderSender $OrderSender,
        DateTime $date,
        CollectionFactory $orderCollectionFactory,
        Builder $_transactionBuilder,
        DBTransaction $dbTransaction,
        Order $order,
        Config $config,
        State $state,
        FortisHelper $fortishelper,
        TransactionSearchResultInterfaceFactory $transactionSearchResultInterfaceFactory,
        EncryptorInterface $encryptor,
        RequestInterface $request,
        ResultFactory $resultFactory
    ) {
        $pre = __METHOD__ . " : ";

        $this->_logger = $logger;

        $this->_logger->debug($pre . 'bof');

        $this->dbTransaction                           = $dbTransaction;
        $this->order                                   = $order;
        $this->config                                  = $config;
        $this->transactionSearchResultInterfaceFactory = $transactionSearchResultInterfaceFactory;
        $this->_customerSession                        = $customerSession;
        $this->_checkoutSession                        = $checkoutSession;
        $this->_orderFactory                           = $orderFactory;
        $this->fortisSession                           = $fortisSession;
        $this->_urlHelper                              = $urlHelper;
        $this->_customerUrl                            = $customerUrl;
        $this->pageFactory                             = $pageFactory;
        $this->_invoiceService                         = $invoiceService;
        $this->invoiceSender                           = $invoiceSender;
        $this->OrderSender                             = $OrderSender;
        $this->_transactionFactory                     = $transactionFactory;
        $this->_paymentMethod                          = $paymentMethod;
        $this->_urlBuilder                             = $urlBuilder;
        $this->orderRepository                         = $orderRepository;
        $this->_storeManager                           = $storeManager;
        $this->_date                                   = $date;
        $this->_orderCollectionFactory                 = $orderCollectionFactory;
        $this->_transactionBuilder                     = $_transactionBuilder;
        $this->encryptor                               = $encryptor;
        $this->request                                 = $request;
        $this->resultFactory                           = $resultFactory;

        $this->state         = $state;
        $this->_fortishelper = $fortishelper;

        parent::__construct($context);

        $this->_logger->debug($pre . 'eof');
    }

    /**
     * Custom getter for payment configuration
     *
     * @param string $field i.e fortis_id, test_mode
     *
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getConfigData($field)
    {
        $configValue = $this->_paymentMethod->getConfigData($field);
        if (in_array($field, self::$encryptedConfigKeys)) {
            $configValue = $this->encryptor->decrypt($configValue);
        }

        return $configValue;
    }

    /**
     * Get Customer Before Auth Url
     *
     * @return string|void|null
     */
    public function getCustomerBeforeAuthUrl()
    {
        // TODO

        return null;
    }

    /**
     * Returns a list of action flags [flag_key] => boolean
     *
     * @return array
     */
    public function getActionFlagList()
    {
        return [];
    }

    /**
     * Returns login url parameter for redirect
     *
     * @return string
     */
    public function getLoginUrl()
    {
        return $this->_customerUrl->getLoginUrl();
    }

    /**
     * Returns action name which requires redirect
     *
     * @return string
     */
    public function getRedirectActionName()
    {
        return 'index';
    }

    /**
     * Redirect to login page
     *
     * @return void
     */
    public function redirectLogin()
    {
        $this->_actionFlag->set('', 'no-dispatch', true);
        $this->_customerSession->setBeforeAuthUrl($this->_redirect->getRefererUrl());
        $this->getResponse()->setRedirect(
            $this->_urlHelper->addRequestParam($this->_customerUrl->getLoginUrl(), ['context' => 'checkout'])
        );
    }

    /**
     * Instantiate
     *
     * @return void
     * @throws LocalizedException
     */
    protected function _initCheckout()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
        $this->_order = $this->_checkoutSession->getLastRealOrder();

        if (!$this->_order->getId()) {
            $this->getResponse()->setStatusHeader(404, '1.1', 'Not found');
            throw new LocalizedException(__('We could not find "Order" for processing'));
        }

        if ($this->_order->getState() != Order::STATE_PENDING_PAYMENT) {
            $this->_order->setState(
                Order::STATE_PENDING_PAYMENT
            )->save();
        }

        if ($this->_order->getQuoteId()) {
            $this->_checkoutSession->setFortisQuoteId($this->_checkoutSession->getQuoteId());
            $this->_checkoutSession->setFortisSuccessQuoteId($this->_checkoutSession->getLastSuccessQuoteId());
            $this->_checkoutSession->setFortisRealOrderId($this->_checkoutSession->getLastRealOrderId());
            $this->_checkoutSession->getQuote()->setIsActive(false)->save();
        }

        $this->_logger->debug($pre . 'eof');
    }

    /**
     * Fortis session instance getter
     *
     * @return Generic
     */
    protected function _getSession()
    {
        return $this->fortisSession;
    }

    /**
     * Return checkout session object
     *
     * @return CheckoutSession
     */
    protected function _getCheckoutSession()
    {
        return $this->_checkoutSession;
    }

    /**
     * Return checkout quote object
     *
     * @return Quote
     */
    protected function _getQuote()
    {
        if (!$this->_quote) {
            $this->_quote = $this->_getCheckoutSession()->getQuote();
        }

        return $this->_quote;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
