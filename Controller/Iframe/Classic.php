<?php

namespace Fortispay\Fortis\Controller\Iframe;

use Fortispay\Fortis\Controller\AbstractFortis;
use Fortispay\Fortis\Helper\Data as FortisHelper;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\Fortis;
use Fortispay\Fortis\Model\Payment\IFrameData;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\DB\Transaction as DBTransaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Session\Generic;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Url\Helper\Data;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Classic extends AbstractFortis
{
    /**
     * @var ResultFactory
     */
    protected $resultRawFactory;

    /**
     * @var IFrameData
     */
    protected IFrameData $iFrameData;

    /**
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
     * @param ManagerInterface $messageManager
     * @param JsonFactory $resultJsonFactory
     * @param TransactionRepositoryInterface $transactionRepository
     * @param ResourceConnection $resourceConnection
     * @param EventManager $eventManager
     * @param CountryFactory $countryFactory
     * @param CountryCollectionFactory $countryCollectionFactory
     * @param IFrameData $iFrame
     */
    public function __construct(
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
        ResultFactory $resultFactory,
        ManagerInterface $messageManager,
        JsonFactory $resultJsonFactory,
        TransactionRepositoryInterface $transactionRepository,
        ResourceConnection $resourceConnection,
        EventManager $eventManager,
        CountryFactory $countryFactory,
        CountryCollectionFactory $countryCollectionFactory,
        IFrameData $iFrameData
    ) {
        $this->resultRawFactory = $resultFactory;
        $this->iFrameData       = $iFrameData;
        parent::__construct(
            $pageFactory,
            $customerSession,
            $checkoutSession,
            $orderFactory,
            $fortisSession,
            $urlHelper,
            $customerUrl,
            $logger,
            $transactionFactory,
            $invoiceService,
            $invoiceSender,
            $paymentMethod,
            $urlBuilder,
            $orderRepository,
            $storeManager,
            $OrderSender,
            $date,
            $orderCollectionFactory,
            $_transactionBuilder,
            $dbTransaction,
            $order,
            $config,
            $state,
            $fortishelper,
            $transactionSearchResultInterfaceFactory,
            $encryptor,
            $request,
            $resultFactory,
            $messageManager,
            $resultJsonFactory,
            $transactionRepository,
            $resourceConnection,
            $eventManager,
            $countryFactory,
            $countryCollectionFactory,
            $iFrameData
        );
    }

    public function execute()
    {
        $pageObject = $this->pageFactory->create();

        $blockContent = $pageObject->getLayout()
                                   ->getBlock('fortis_redirect')
                                   ->toHtml();

        $resultRaw = $this->resultRawFactory->create(ResultFactory::TYPE_RAW);
        $resultRaw->setContents($blockContent);

        return $resultRaw;
    }
}
