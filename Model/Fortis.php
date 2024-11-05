<?php

namespace Fortispay\Fortis\Model;

use Fortispay\Fortis\Block\Form;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Block\Info;
use Magento\Framework\Event\ManagerInterface;
use Fortispay\Fortis\Service\FortisMethodService;
use Magento\Directory\Helper\Data as DirectoryHelper;

/**
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Fortis implements MethodInterface
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var OrderRepositoryInterface $orderRepository
     */
    private $orderRepository;

    private ScopeConfigInterface $scopeConfig;
    private InfoInterface $infoInstance;
    /**
     * @var string
     */
    private $formBlockType = Form::class;

    /**
     * @var string
     */
    private $infoBlockType = Info::class;

    /**
     * @var ManagerInterface
     */
    private ManagerInterface $eventManager;
    private FortisMethodService $fortisMethodService;
    private Config $config;
    private DirectoryHelper $directoryHelper;
    private FortisApi $fortisApi;

    /**
     * Construct
     *
     * @param ManagerInterface $eventManager
     * @param ScopeConfigInterface $scopeConfig
     * @param ConfigFactory $configFactory
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param EncryptorInterface $encryptor
     * @param OrderRepositoryInterface $orderRepository
     * @param FortisMethodService $fortisMethodService
     * @param DirectoryHelper $directoryHelper
     * @param FortisApi $fortisApi
     */
    public function __construct(
        ManagerInterface $eventManager,
        ScopeConfigInterface $scopeConfig,
        ConfigFactory $configFactory,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        EncryptorInterface $encryptor,
        OrderRepositoryInterface $orderRepository,
        FortisMethodService $fortisMethodService,
        DirectoryHelper $directoryHelper,
        FortisApi $fortisApi
    ) {
        $this->eventManager        = $eventManager;
        $this->storeManager        = $storeManager;
        $this->urlBuilder          = $urlBuilder;
        $this->encryptor           = $encryptor;
        $this->orderRepository     = $orderRepository;
        $this->scopeConfig         = $scopeConfig;
        $this->fortisMethodService = $fortisMethodService;
        $this->directoryHelper     = $directoryHelper;
        $this->fortisApi           = $fortisApi;

        $parameters = ['params' => [Config::METHOD_CODE]];

        $this->config = $configFactory->create($parameters);
    }

    /**
     * Store setter; also updates store ID in config object
     *
     * @param Store|int $store
     *
     * @return $this
     * @throws NoSuchEntityException
     */
    public function setStore($store)
    {
        if (null === $store) {
            $store = $this->storeManager->getStore()->getId();
        }
        $this->config->setStoreId(is_object($store) ? $store->getId() : $store);

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
        return $this->config->isCurrencyCodeSupported($currencyCode);
    }

    /**
     * Payment action getter compatible with payment model
     *
     * @return string
     * @see \Magento\Sales\Model\Payment::place()
     */
    public function getConfigPaymentAction()
    {
        return $this->config->getPaymentAction();
    }

    /**
     * Check whether payment method can be used
     *
     * @param CartInterface|null $quote
     *
     * @return bool
     */
    public function isAvailable(CartInterface $quote = null)
    {
        return $this->config->isMethodAvailable();
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
        return $this->urlBuilder->getUrl('fortis/redirect');
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

        return $this;
    }

    public function canRefund()
    {
        return true;
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
     * @param InfoInterface $payment
     * @param float $amount
     *
     * @return bool
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function refund(InfoInterface $payment, $amount)
    {
        return $this->fortisMethodService->refundOnline($payment, $amount);
    }

    /**
     * Fetch Transaction Info
     *
     * @param InfoInterface $payment
     * @param int $transactionId
     *
     * @return array
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId)
    {
        return [];
    }

    /**
     * Get Store Name
     *
     * @return mixed
     */
    public function getStoreName()
    {
        return $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getCode()
    {
        return Config::METHOD_CODE;
    }

    public function getFormBlockType()
    {
        return $this->formBlockType;
    }

    public function getTitle()
    {
        return $this->getConfigData('title');
    }

    public function getStore()
    {
        return $this->config->getStoreId();
    }

    public function canOrder()
    {
        return true;
    }

    public function canAuthorize()
    {
        return true;
    }

    public function canCapture()
    {
        return true;
    }

    public function canCapturePartial()
    {
        return false;
    }

    public function canCaptureOnce()
    {
        return false;
    }

    public function canVoid()
    {
        return false;
    }

    public function canUseInternal()
    {
        return true;
    }

    public function canUseCheckout()
    {
        return true;
    }

    public function canEdit()
    {
        return true;
    }

    public function canFetchTransactionInfo()
    {
        return false;
    }

    public function isGateway()
    {
        return false;
    }

    public function isOffline()
    {
        return false;
    }

    public function isInitializeNeeded()
    {
        return true;
    }

    public function canUseForCountry($country)
    {
        /*
        for specific country, the flag will set up as 1
        */
        if ($this->getConfigData('allowspecific') === 1) {
            $availableCountries = explode(',', $this->getConfigData('specificcountry') ?? '');
            if (!in_array($country, $availableCountries)) {
                return false;
            }
        }

        return true;
    }

    public function getInfoBlockType()
    {
        return $this->infoBlockType;
    }

    /**
     * @inheritdoc
     */
    public function getInfoInstance()
    {
        return $this->infoInstance;
    }

    /**
     * @inheritdoc
     */
    public function setInfoInstance(InfoInterface $info)
    {
        $this->infoInstance = $info;
    }

    /**
     * @return $this|Fortis
     * @throws LocalizedException
     */
    public function validate()
    {
        /**
         * to validate payment method is allowed for billing country or not
         */
        $paymentInfo = $this->getInfoInstance();
        if ($paymentInfo instanceof Payment) {
            $billingCountry = $paymentInfo->getOrder()->getBillingAddress()->getCountryId();
        } else {
            $billingCountry = $paymentInfo->getQuote()->getBillingAddress()->getCountryId();
        }
        $billingCountry = $billingCountry ?: $this->directoryHelper->getDefaultCountry();

        if (!$this->canUseForCountry($billingCountry)) {
            throw new LocalizedException(
                __('You can\'t use the payment type you selected to make payments to the billing country.')
            );
        }

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param $amount
     *
     * @return $this|Fortis
     * @throws LocalizedException
     */
    public function order(InfoInterface $payment, $amount)
    {
        if (!$this->canOrder()) {
            throw new LocalizedException(__('The order action is not available.'));
        }

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param $amount
     *
     * @return $this|Fortis
     * @throws LocalizedException
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        if (!$this->canAuthorize()) {
            throw new LocalizedException(__('The authorize action is not available.'));
        }

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param $amount
     *
     * @return $this|Fortis
     * @throws LocalizedException
     */
    public function capture(InfoInterface $payment, $amount)
    {
        if (!$this->canCapture()) {
            throw new LocalizedException(__('The capture action is not available.'));
        }

        $d = json_decode($payment->getAdditionalInformation()['raw_details_info'] ?? "");

        // Check for non-auth-only
        if (!$d || $d->payment_method === 'ach' || !isset($d->auth_amount)) {
            return $this;
        }

        $order = $payment->getOrder();

        $orderID = $order->getId();

        $authAmount    = $d->auth_amount;
        $transactionId = $d->id;

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));

        // Do auth transaction
        $intentData = [
            'transaction_amount' => $authAmount,
            "order_number"       => $orderID,
            'transactionId'      => $transactionId,
        ];

        $this->fortisApi->doCompleteAuthTransaction($intentData, $user_id, $user_api_key);

        return $this;
    }

    /**
     * @param InfoInterface $payment
     *
     * @return $this|Fortis
     */
    public function cancel(InfoInterface $payment)
    {
        return $this;
    }

    /**
     * @param InfoInterface $payment
     *
     * @return $this|Fortis
     * @throws LocalizedException
     */
    public function void(InfoInterface $payment)
    {
        if (!$this->canVoid()) {
            throw new LocalizedException(__('The void action is not available.'));
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function canReviewPayment()
    {
        return true;
    }

    /**
     * @param InfoInterface $payment
     *
     * @return false
     * @throws LocalizedException
     */
    public function acceptPayment(InfoInterface $payment)
    {
        if (!$this->canReviewPayment()) {
            throw new LocalizedException(__('The payment review action is unavailable.'));
        }

        return false;
    }

    /**
     * @param InfoInterface $payment
     *
     * @return false
     * @throws LocalizedException
     */
    public function denyPayment(InfoInterface $payment)
    {
        if (!$this->canReviewPayment()) {
            throw new LocalizedException(__('The payment review action is unavailable.'));
        }

        return false;
    }

    /**
     * @param $field
     * @param $storeId
     *
     * @return mixed|string
     */
    public function getConfigData($field, $storeId = null)
    {
        if ('order_place_redirect_url' === $field) {
            return $this->getOrderPlaceRedirectUrl();
        }
        if (null === $storeId) {
            $storeId = $this->getStore();
        }
        $path = 'payment/' . $this->getCode() . '/' . $field;

        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheritdoc
     *
     * @param DataObject $data
     *
     * @return $this
     * @throws LocalizedException
     */
    public function assignData(DataObject $data)
    {
        $this->eventManager->dispatch(
            'payment_method_assign_data_' . $this->getCode(),
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE  => $this->getInfoInstance(),
                AbstractDataAssignObserver::DATA_CODE   => $data
            ]
        );

        $this->eventManager->dispatch(
            'payment_method_assign_data',
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE  => $this->getInfoInstance(),
                AbstractDataAssignObserver::DATA_CODE   => $data
            ]
        );

        return $this;
    }

    /**
     * @param $storeId
     *
     * @return bool
     */
    public function isActive($storeId = null)
    {
        return (bool)(int)$this->getConfigData('active', $storeId);
    }
}
