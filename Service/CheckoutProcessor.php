<?php

namespace Fortispay\Fortis\Service;

use Fortispay\Fortis\Model\Fortis;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\QuoteRepository;
use Magento\Customer\Model\Url;

class CheckoutProcessor
{
    private const CART_URL = 'checkout/cart';
    /**
     * @var array|string[]
     */
    private static array $encryptedConfigKeys = [
        'user_id',
        'user_api_key',
    ];
    private LoggerInterface $logger;
    private Order $order;
    private OrderRepositoryInterface $orderRepository;
    private CheckoutSession $checkoutSession;
    private QuoteRepository $quoteRepository;
    private ResultFactory $resultFactory;
    private Url $customerUrl;
    private Fortis $paymentMethod;
    private EncryptorInterface $encryptor;

    /**
     * @param LoggerInterface $logger
     * @param Order $order
     * @param OrderRepositoryInterface $orderRepository
     * @param CheckoutSession $checkoutSession
     * @param QuoteRepository $quoteRepository
     * @param ResultFactory $resultFactory
     * @param Url $customerUrl
     * @param Fortis $paymentMethod
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        LoggerInterface $logger,
        Order $order,
        OrderRepositoryInterface $orderRepository,
        CheckoutSession $checkoutSession,
        QuoteRepository $quoteRepository,
        ResultFactory $resultFactory,
        Url $customerUrl,
        Fortis $paymentMethod,
        EncryptorInterface $encryptor
    ) {
        $this->logger = $logger;
        $this->order = $order;
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->resultFactory = $resultFactory;
        $this->customerUrl = $customerUrl;
        $this->paymentMethod = $paymentMethod;
        $this->encryptor = $encryptor;
    }

    /**
     * Instantiate
     *
     * @return void
     * @throws LocalizedException
     */
    public function initCheckout(): void
    {
        $pre = __METHOD__ . " : ";
        $this->logger->debug($pre . 'bof');
        $this->order = $this->checkoutSession->getLastRealOrder();

        if (!$this->order->getId()) {
            throw new LocalizedException(__('We could not find "Order" for processing'));
        }

        if ($this->order->getState() != Order::STATE_PENDING_PAYMENT) {
            $this->order->setState(
                Order::STATE_PENDING_PAYMENT
            );
            $this->orderRepository->save($this->order);
        }

        if ($this->order->getQuoteId()) {
            $this->checkoutSession->setFortisQuoteId($this->checkoutSession->getQuoteId());
            $this->checkoutSession->setFortisSuccessQuoteId($this->checkoutSession->getLastSuccessQuoteId());
            $this->checkoutSession->setFortisRealOrderId($this->checkoutSession->getLastRealOrderId());

            $quote = $this->checkoutSession->getQuote();
            $quote->setIsActive(false);
            $this->quoteRepository->save($quote);
        }

        $this->logger->debug($pre . 'eof');
    }

    public function getRedirectToCartObject(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setUrl(self::CART_URL);

        return $redirect;
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
        $configValue = $this->paymentMethod->getConfigData($field);
        if (in_array($field, self::$encryptedConfigKeys)) {
            $configValue = $this->encryptor->decrypt($configValue);
        }

        return $configValue;
    }

    /**
     * Returns login url parameter for redirect
     *
     * @return string
     */
    public function getLoginUrl(): string
    {
        return $this->customerUrl->getLoginUrl();
    }
}
