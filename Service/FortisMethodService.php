<?php

namespace Fortispay\Fortis\Service;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File;
use Magento\Vault\Model\ResourceModel\PaymentToken as PaymentTokenResourceModel;
use Psr\Log\LoggerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\UrlInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Model\PaymentTokenFactory;
use Magento\Vault\Model\ResourceModel\PaymentToken;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use StdClass;
use DateTime;
use DateTimeZone;
use DateInterval;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;

class FortisMethodService
{
    public const        SECURE             = ['_secure' => true];
    public const        FFFFFF             = '#ffffff';
    public const        AVAILABLE_CC_TYPES = [
        'visa' => 'VI',
        'mc'   => 'MC',
        'disc' => 'DI',
        'amex' => 'AE'
    ];
    private static array $configKeys = [
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
        'fortis_single_view',
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
        'fortis_cancel_order_btn_text'
    ];

    /**
     * @var array|string[]
     */
    public static array $encryptedConfigKeys = [
        'user_id',
        'user_api_key',
    ];

    private Config $config;
    private LoggerInterface $logger;
    private File $fileIo;
    private DirectoryList $directoryList;
    private CheckoutSession $checkoutSession;
    private UrlInterface $urlBuilder;
    private EncryptorInterface $encryptor;
    private ScopeConfigInterface $scopeConfig;
    private PaymentTokenRepositoryInterface $paymentTokenRepository;
    private PaymentTokenManagementInterface $paymentTokenManagement;
    private PaymentTokenFactory $paymentTokenFactory;
    private PaymentTokenResourceModel $paymentTokenResourceModel;
    private OrderRepositoryInterface $orderRepository;
    private FileDriver $driver;
    private FortisApi $fortisApi;
    private Builder $transactionBuilder;

    /**
     * @param Config $config
     * @param LoggerInterface $logger
     * @param File $fileIo
     * @param DirectoryList $directoryList
     * @param CheckoutSession $checkoutSession
     * @param UrlInterface $urlBuilder
     * @param EncryptorInterface $encryptor
     * @param ScopeConfigInterface $scopeConfig
     * @param PaymentTokenRepositoryInterface $paymentTokenRepository
     * @param PaymentTokenManagementInterface $paymentTokenManagement
     * @param PaymentTokenFactory $paymentTokenFactory
     * @param PaymentToken $paymentTokenResourceModel
     * @param OrderRepositoryInterface $orderRepository
     * @param FileDriver $driver
     * @param FortisApi $fortisApi
     */
    public function __construct(
        Config $config,
        LoggerInterface $logger,
        File $fileIo,
        DirectoryList $directoryList,
        CheckoutSession $checkoutSession,
        UrlInterface $urlBuilder,
        EncryptorInterface $encryptor,
        ScopeConfigInterface $scopeConfig,
        PaymentTokenRepositoryInterface $paymentTokenRepository,
        PaymentTokenManagementInterface $paymentTokenManagement,
        PaymentTokenFactory $paymentTokenFactory,
        PaymentTokenResourceModel $paymentTokenResourceModel,
        OrderRepositoryInterface $orderRepository,
        FileDriver $driver,
        FortisApi $fortisApi,
        Builder $transactionBuilder,
    ) {
        $this->config                    = $config;
        $this->logger                    = $logger;
        $this->fileIo                    = $fileIo;
        $this->directoryList             = $directoryList;
        $this->checkoutSession           = $checkoutSession;
        $this->urlBuilder                = $urlBuilder;
        $this->encryptor                 = $encryptor;
        $this->scopeConfig               = $scopeConfig;
        $this->paymentTokenRepository    = $paymentTokenRepository;
        $this->paymentTokenManagement    = $paymentTokenManagement;
        $this->paymentTokenFactory       = $paymentTokenFactory;
        $this->paymentTokenResourceModel = $paymentTokenResourceModel;
        $this->orderRepository           = $orderRepository;
        $this->driver                    = $driver;
        $this->fortisApi                 = $fortisApi;
        $this->transactionBuilder        = $transactionBuilder;

        $this->checkApplePayFile();
    }

    public function checkApplePayFile()
    {
        try {
            if ($this->config->applePayIsActive()) {
                $currentFolder = $this->directoryList->getRoot();
                $file          = 'apple-developer-merchantid-domain-association';
                $source        = $currentFolder . '/app/code/Fortispay/Fortis/' . $file;
                $targetDir     = $currentFolder . '/pub/.well-known/';
                $target        = $targetDir . $file;

                if (!$this->driver->isDirectory($targetDir)) {
                    $this->fileIo->mkdir($targetDir, 0755);
                }

                if ($this->fileIo->fileExists($source) && !$this->fileIo->fileExists($target)) {
                    $this->fileIo->cp($source, $target);
                }
            }
        } catch (FileSystemException $e) {
            $this->logger->error('Error copying Apple Pay file: ' . $e->getMessage());
        }
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
            $creds[$key] = $this->config->getConfig($key);

            if (in_array($key, self::$encryptedConfigKeys)) {
                $creds[$key] = $this->encryptor->decrypt($creds[$key]);
            }
        }

        return $creds;
    }

    /**
     * This is where we compile data posted by the form
     *
     * @param bool $saveAccount
     *
     * @return array
     * @throws Exception|LocalizedException
     */
    public function getFortisOrderToken(bool $saveAccount)
    {
        // Variable initialization
        $saveAccount          = $saveAccount && $this->config->saveAccount();
        $productTransactionId = $this->config->ccProductId();
        $achEnabled           = $this->config->achIsActive();
        $achProductId         = $this->config->achProductId();

        $order      = $this->checkoutSession->getLastRealOrder();
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
        $api                    = $this->fortisApi;
        $client_token           = $api->getClientToken($intentData, $user_id, $user_api_key);
        $body                   = json_decode($api->getTokenBody($client_token));
        $product_transaction_id = $body->transaction->methods[0]->product_transaction_id;
        $order->addCommentToStatusHistory("product_transaction_id:$product_transaction_id");
        $this->orderRepository->save($order);

        return [
            'token'       => $client_token,
            'options'     => $options,
            'redirectUrl' => $returnUrl,
            'googlepay'   => $this->config->googlePayIsActive(),
            'applepay'    => $this->config->applePayIsActive(),
        ];
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

        $this->logger->debug($pre . 'serverMode : ' . $this->config->getConfig('test_mode'));

        $cred = $this->getFortisCredentials();

        $user_id      = $cred['user_id'];
        $user_api_key = $cred['user_api_key'];
        $view         = ($cred['fortis_single_view'] === 'single') ? 'card-single-field' : 'default';

        $options = [
            'main_options'       => [
                'theme'                   => $cred['fortis_theme'] ?? 'default',
                'environment'             => $cred['fortis_environment'] ?? 'production',
                'view'                    => $view,
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
                'cancelButtonText'              => $cred['fortis_cancel_order_btn_text'] ?? 'Cancel'
            ],
        ];

        $billing = $order->getBillingAddress();
        $billing->getCountryId();

        $action = $this->config->orderAction();
        if ($action === 'sale') {
            $returnUrl = $this->urlBuilder->getUrl(
                'fortis/redirect/success',
                self::SECURE
            ) . '?gid=' . $order->getId();
        } else {
            $returnUrl = $this->urlBuilder->getUrl(
                'fortis/redirect/authorise',
                self::SECURE
            ) . '?gid=' . $order->getId();
        }

        return [$user_id, $user_api_key, $action, $options, $returnUrl];
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
    public function refundOnline(InfoInterface $payment, float $amount)
    {
        $order = $payment->getOrder();

        if ($order->getStatus() !== Order::STATE_PROCESSING) {
            if ($this->isOrderVirtualOnly($order)) {
                $order->setState(Order::STATE_NEW);
                $order->setStatus(Order::STATE_NEW);
                $this->orderRepository->save($order);
            } else {
                $order->setState(Order::STATE_PROCESSING);
                $order->setStatus(Order::STATE_PROCESSING);
            }
        }

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));
        $api          = $this->fortisApi;

        $paymentMethod         = 'cc';
        $additionalInformation = $payment->getAdditionalInformation();
        $rawDetailsInfo        = null;
        if (!empty($additionalInformation) && !empty($additionalInformation['raw_details_info'])) {
            $rawDetailsInfo = json_decode($additionalInformation['raw_details_info']);
            $paymentMethod  = property_exists(
                $rawDetailsInfo,
                'payment_method'
            ) ? $rawDetailsInfo->payment_method : $paymentMethod;
        }

        $transactionId = $rawDetailsInfo?->id;
        $intentData    = [
            'transaction_amount' => (int)($amount * 100),
            'transactionId'      => $transactionId,
            'description'        => $order->getIncrementId()
        ];

        try {
            if ($paymentMethod !== 'ach') {
                $response = $api->refundTransactionAmount($intentData, $user_id, $user_api_key);
            } else {
                $intentData = [
                    'transaction_amount'      => $intentData['transaction_amount'],
                    'description'             => $order->getIncrementId(),
                    'previous_transaction_id' => $transactionId,
                ];
                $response   = $api->achRefundTransactionAmount($intentData);
            }

            $data = json_decode($response)->data ?? null;

            /* Set Comment to Order*/
            if ($data?->reason_code_id === 1000) {
                $order->addCommentToStatusHistory(
                    __(
                        "Order Successfully Refunded with Transaction Id - $data->id Auth Code - $data->auth_code"
                    )
                );
                $this->orderRepository->save($order);

                return true;
            } else {
                $order->addCommentToStatusHistory(__("Refund not successful"));
                $this->orderRepository->save($order);

                return false;
            }
        } catch (Exception $exception) {
            $order->addCommentToStatusHistory(__("Refund not successful"));
            $this->orderRepository->save($order);

            return false;
        }
    }

    /**
     * @param InfoInterface $payment
     *
     * @return void
     * @throws LocalizedException
     */
    public function voidOnline(InfoInterface $payment): void
    {
        $transactionId = $payment->getLastTransId();
        $order         = $payment->getOrder();

        $rawDetailsInfo = json_decode($payment->getAdditionalInformation()['raw_details_info']);
        $authAmount     = $rawDetailsInfo->auth_amount;

        $user_id      = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_id'));
        $user_api_key = $this->encryptor->decrypt($this->scopeConfig->getValue('payment/fortis/user_api_key'));

        // Do auth transaction
        $intentData = [
            'transaction_amount' => $authAmount,
            'token_id'           => $rawDetailsInfo->token_id,
            'transactionId'      => $transactionId,
        ];

        $response = $this->fortisApi->voidAuthAmount($intentData, $user_id, $user_api_key);

        if ($response) {
            // Create a void transaction
            $data       = json_decode($response)->data;
            $newPayment = $order->getPayment();
            $newPayment->setAmountAuthorized($authAmount / 100.0);
            $payment->setLastTransId($data->id)
                    ->setTransactionId($data->id)
                    ->setAdditionalInformation(
                        [Transaction::RAW_DETAILS => json_encode($response)]
                    );
            $transaction = $this->transactionBuilder->setPayment($payment)
                                                    ->setOrder($order)
                                                    ->setTransactionId($data->id)
                                                    ->setAdditionalInformation(
                                                        [Transaction::RAW_DETAILS => json_encode($response)]
                                                    )
                                                    ->setFailSafe(true)
                                                    ->build(TransactionInterface::TYPE_VOID);

            $message = __('The authorised amount has been voided');
            $payment->addTransactionCommentsToOrder($transaction, $message);
            $payment->setParentTransactionId($transactionId);
            $order->setShouldCloseParentTransaction(true);
            $order->setStatus(Order::STATE_CLOSED);
            $order->setState(Order::STATE_CLOSED);
            $this->orderRepository->save($order);
        }
    }

    /**
     * Save Vault Data
     *
     * @param Order $order
     * @param StdClass $data
     *
     * @return void
     */
    public function saveVaultData(Order $order, StdClass $data)
    {
        if (((int)($this->config->getConfig('fortis_cc_vault_active') ?? 0) !== 1) ||
            !isset($data->saved_account)
        ) {
            return;
        }

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

    public function createExpiryDate(): string
    {
        $one_year_from_now_timestamp = strtotime('+1 year');

        return date('my', $one_year_from_now_timestamp);
    }

    /**
     * Check if the order contains only virtual products.
     *
     * @param Order $order
     *
     * @return bool
     */
    private function isOrderVirtualOnly(Order $order)
    {
        foreach ($order->getAllItems() as $item) {
            if (!$item->getProduct()->isVirtual()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate vault payment public hash
     *
     * @param PaymentTokenInterface|null $paymentToken
     *
     * @return string
     */
    private function generatePublicHash(PaymentTokenInterface|null $paymentToken)
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
