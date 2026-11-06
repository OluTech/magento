<?php

namespace Fortispay\Fortis\Model;

use Fortispay\Fortis\Block\Form;
use Fortispay\Fortis\Service\FortisMethodService;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Payment\Block\Info;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

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
    private StoreManagerInterface $storeManager;

    /**
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @var OrderRepositoryInterface $orderRepository
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var InfoInterface
     */
    private InfoInterface $infoInstance;

    /**
     * @var string
     */
    private string $formBlockType = Form::class;

    /**
     * @var string
     */
    private string $infoBlockType = Info::class;

    /**
     * @var ManagerInterface
     */
    private ManagerInterface $eventManager;

    /**
     * @var FortisMethodService
     */
    private FortisMethodService $fortisMethodService;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var DirectoryHelper
     */
    private DirectoryHelper $directoryHelper;

    /**
     * @var FortisApi
     */
    private FortisApi $fortisApi;

    /**
     * @var array<string>
     */
    private static array $encryptedConfigKeys = [
        'user_id',
        'user_api_key',
    ];

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
    public function setStore($store): static
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
    public function canUseForCurrency($currencyCode): bool
    {
        return $this->config->isCurrencyCodeSupported($currencyCode);
    }

    /**
     * Payment action getter compatible with payment model
     *
     * @return string
     * @see \Magento\Sales\Model\Payment::place()
     */
    public function getConfigPaymentAction(): ?string
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
    public function isAvailable(?CartInterface $quote = null): bool
    {
        return $this->config->isMethodAvailable();
    }

    /**
     * Get Order Place Redirect Url
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        return $this->getCheckoutRedirectUrl();
    }

    /**
     * Checkout redirect URL getter for onepage checkout (hardcode)
     *
     * @return string
     */
    public function getCheckoutRedirectUrl(): string
    {
        return $this->urlBuilder->getUrl('fortis/redirect');
    }

    /**
     * Initialize
     *
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return static
     */
    public function initialize($paymentAction, $stateObject): static
    {
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * Whether refund action is available.
     *
     * @return bool
     */
    public function canRefund(): bool
    {
        return true;
    }

    /**
     * Check partial refund availability for invoice
     *
     * @return bool
     * @api
     */
    public function canRefundPartialPerInvoice(): bool
    {
        return $this->canRefund();
    }

    /**
     * Refund specified amount for payment
     *
     * @param InfoInterface $payment
     * @param float $amount
     *
     * @return static
     * @throws LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function refund(InfoInterface $payment, $amount): static
    {
        $this->fortisMethodService->refundOnline($payment, $amount);

        return $this;
    }

    /**
     * Fetch Transaction Info
     *
     * @param InfoInterface $payment
     * @param string $transactionId
     *
     * @return array
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId): array
    {
        return [];
    }

    /**
     * Get Store Name
     *
     * @return mixed
     */
    public function getStoreName(): mixed
    {
        return $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get payment method code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return Config::METHOD_CODE;
    }

    /**
     * Get form block class.
     *
     * @return string
     */
    public function getFormBlockType(): string
    {
        return $this->formBlockType;
    }

    /**
     * Get payment title.
     *
     * @return mixed
     */
    public function getTitle(): mixed
    {
        return $this->getConfigData('title');
    }

    /**
     * Get configured store ID.
     *
     * @return int|string|null
     */
    public function getStore(): int|string|null
    {
        return $this->config->getStoreId();
    }

    /**
     * Whether order action is available.
     *
     * @return bool
     */
    public function canOrder(): bool
    {
        return true;
    }

    /**
     * Whether authorize action is available.
     *
     * @return bool
     */
    public function canAuthorize(): bool
    {
        return true;
    }

    /**
     * Whether capture action is available.
     *
     * @return bool
     */
    public function canCapture(): bool
    {
        $payment = $this->getPaymentInfo();

        $order = $payment->getOrder();

        if ($order->getStatus() === Order::STATE_PENDING_PAYMENT) {
            return false;
        }

        return true;
    }

    /**
     * Whether partial capture is available.
     *
     * @return bool
     */
    public function canCapturePartial(): bool
    {
        return false;
    }

    /**
     * Whether one-time capture restriction is active.
     *
     * @return bool
     */
    public function canCaptureOnce(): bool
    {
        return false;
    }

    /**
     * Whether void action is available.
     *
     * @return bool
     */
    public function canVoid(): bool
    {
        return true;
    }

    /**
     * Whether method can be used in admin area.
     *
     * @return bool
     */
    public function canUseInternal(): bool
    {
        return true;
    }

    /**
     * Whether method can be used at checkout.
     *
     * @return bool
     */
    public function canUseCheckout(): bool
    {
        return true;
    }

    /**
     * Whether method can be edited.
     *
     * @return bool
     */
    public function canEdit(): bool
    {
        return true;
    }

    /**
     * Whether transaction info retrieval is available.
     *
     * @return bool
     */
    public function canFetchTransactionInfo(): bool
    {
        return false;
    }

    /**
     * Whether this is a gateway method.
     *
     * @return bool
     */
    public function isGateway(): bool
    {
        return false;
    }

    /**
     * Whether this is an offline method.
     *
     * @return bool
     */
    public function isOffline(): bool
    {
        return false;
    }

    /**
     * Whether initialization is required.
     *
     * @return bool
     */
    public function isInitializeNeeded(): bool
    {
        return true;
    }

    /**
     * Whether method can be used for given country.
     *
     * @param string $country
     * @return bool
     */
    public function canUseForCountry($country): bool
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

    /**
     * Get info block class.
     *
     * @return string
     */
    public function getInfoBlockType(): string
    {
        return $this->infoBlockType;
    }

    /**
     * Get payment info instance
     *
     * @return InfoInterface
     */
    public function getPaymentInfo(): InfoInterface
    {
        return $this->infoInstance;
    }

    /**
     * Return current payment info instance.
     *
     * @deprecated Use getPaymentInfo() instead.
     * @see getPaymentInfo()
     * @return InfoInterface
     */
    public function getInfoInstance(): InfoInterface
    {
        return $this->getPaymentInfo();
    }

    /**
     * @inheritdoc
     */
    public function setInfoInstance(InfoInterface $info): void
    {
        $this->infoInstance = $info;
    }

    /**
     * Validate payment data for billing country constraints.
     *
     * @return static
     * @throws LocalizedException
     */
    public function validate(): static
    {
        /**
         * to validate payment method is allowed for billing country or not
         */
        $paymentInfo = $this->getPaymentInfo();
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
     * Place an order action.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return static
     * @throws LocalizedException
     */
    public function order(InfoInterface $payment, $amount): static
    {
        if (!$this->canOrder()) {
            throw new LocalizedException(__('The order action is not available.'));
        }

        return $this;
    }

    /**
     * Authorize a payment.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return static
     * @throws LocalizedException
     */
    public function authorize(InfoInterface $payment, $amount): static
    {
        if (!$this->canAuthorize()) {
            throw new LocalizedException(__('The authorize action is not available.'));
        }

        return $this;
    }

    /**
     * Capture an authorized payment.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return static
     * @throws LocalizedException
     */
    public function capture(InfoInterface $payment, $amount): static
    {
        if (!$this->canCapture()) {
            throw new LocalizedException(__('The capture action is not available.'));
        }

        $paymentDetails = json_decode($payment->getAdditionalInformation()['raw_details_info'] ?? "");

        // Check for non-auth-only
        if (!$paymentDetails || $paymentDetails->payment_method === 'ach' || !isset($paymentDetails->auth_amount)) {
            return $this;
        }

        $order = $payment->getOrder();

        $orderID = $order->getId();

        $authAmount    = $paymentDetails->auth_amount;
        $transactionId = $paymentDetails->id;

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));

        $subtotalAmount = (int)bcmul(
            (string)($order->getSubtotal() + $order->getShippingAmount() + $order->getDiscountAmount()),
            '100',
            0
        );

        $currencyCode = $order->getOrderCurrencyCode();
        $intentData   = [
            'transaction_amount' => $authAmount,
            "order_number"       => $orderID,
            'transactionId'      => $transactionId,
            'tax'                => ($paymentDetails->tax ?? 0),
            'subtotal_amount'    => $subtotalAmount,
        ];

        $this->fortisMethodService->applySecondaryCurrency($intentData, $currencyCode);

        if (isset($paymentDetails->surcharge->surcharge_amount)) {
            $intentData['subtotal_amount']  -= $paymentDetails->surcharge->surcharge_amount;
            $intentData['surcharge_amount'] = $paymentDetails->surcharge->surcharge_amount;
        } elseif (isset($paymentDetails->surcharge_amount) && $paymentDetails->surcharge_amount > 0) {
            $intentData['subtotal_amount']  -= $paymentDetails->surcharge_amount;
            $intentData['surcharge_amount'] = $paymentDetails->surcharge_amount;
        }

        $this->fortisApi->doCompleteAuthTransaction($intentData, $user_id, $user_api_key);

        return $this;
    }

    /**
     * Cancel a payment.
     *
     * @param InfoInterface $payment
     * @return static
     * @throws LocalizedException
     */
    public function cancel(InfoInterface $payment): Fortis|static
    {
        $this->fortisMethodService->voidOnline($payment);

        return $this;
    }

    /**
     * Void an authorized payment.
     *
     * @param InfoInterface $payment
     * @return static
     * @throws LocalizedException
     */
    public function void(InfoInterface $payment): Fortis|static
    {
        if (!$this->canVoid()) {
            throw new LocalizedException(__('The void action is not available.'));
        }

        $this->fortisMethodService->voidOnline($payment);

        return $this;
    }

    /**
     * Return whether payment review is available.
     *
     * @return bool
     */
    public function canReviewPayment(): bool
    {
        return true;
    }

    /**
     * Accept a payment under review.
     *
     * @param InfoInterface $payment
     * @return false
     * @throws LocalizedException
     */
    public function acceptPayment(InfoInterface $payment): bool
    {
        if (!$this->canReviewPayment()) {
            throw new LocalizedException(__('The payment review action is unavailable.'));
        }

        return false;
    }

    /**
     * Deny a payment under review.
     *
     * @param InfoInterface $payment
     * @return false
     * @throws LocalizedException
     */
    public function denyPayment(InfoInterface $payment): bool
    {
        if (!$this->canReviewPayment()) {
            throw new LocalizedException(__('The payment review action is unavailable.'));
        }

        return false;
    }

    /**
     * Return a config field value for this payment method.
     *
     * @param string $field
     * @param int|null $storeId
     * @return mixed|string
     */
    public function getConfigData($field, $storeId = null): mixed
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
     * @return $this
     * @throws LocalizedException
     */
    public function assignData(DataObject $data): static
    {
        $this->eventManager->dispatch(
            'payment_method_assign_data_' . $this->getCode(),
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE  => $this->getPaymentInfo(),
                AbstractDataAssignObserver::DATA_CODE   => $data
            ]
        );

        $this->eventManager->dispatch(
            'payment_method_assign_data',
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE  => $this->getPaymentInfo(),
                AbstractDataAssignObserver::DATA_CODE   => $data
            ]
        );

        return $this;
    }

    /**
     * Return whether the method is currently active.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive($storeId = null): bool
    {
        return (bool)(int)$this->getConfigData('active', $storeId);
    }
}
