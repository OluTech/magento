<?php

namespace Fortispay\Fortis\Model;

use Fortispay\Fortis\Helper\Data as FortisHelper;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Vault\Model\PaymentTokenManagement;
use Psr\Log\LoggerInterface;

class FortisConfigProvider implements ConfigProviderInterface
{
    /**
     * @var ResolverInterface
     */
    protected $localeResolver;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var CurrentCustomer
     */
    protected $currentCustomer;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var fortisHelper
     */
    protected $fortisHelper;

    /**
     * @var string[]
     */
    protected $methodCodes = [
        Config::METHOD_CODE,
    ];

    /**
     * @var AbstractMethod[]
     */
    protected $methods = [];

    /**
     * @var PaymentHelper
     */
    protected $paymentHelper;

    /**
     * @var Repository
     */
    protected $assetRepo;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var PaymentTokenManagement
     */
    private $paymentTokenManagement;

    /**
     * Construct
     *
     * @param LoggerInterface $logger
     * @param ConfigFactory $configFactory
     * @param ResolverInterface $localeResolver
     * @param CurrentCustomer $currentCustomer
     * @param FortisHelper $fortisHelper
     * @param PaymentHelper $paymentHelper
     * @param Repository $assetRepo
     * @param UrlInterface $urlBuilder
     * @param RequestInterface $request
     * @param PaymentTokenManagement $paymentTokenManagement
     *
     * @throws LocalizedException
     */
    public function __construct(
        LoggerInterface $logger,
        ConfigFactory $configFactory,
        ResolverInterface $localeResolver,
        CurrentCustomer $currentCustomer,
        FortisHelper $fortisHelper,
        PaymentHelper $paymentHelper,
        Repository $assetRepo,
        UrlInterface $urlBuilder,
        RequestInterface $request,
        PaymentTokenManagement $paymentTokenManagement
    ) {
        $this->_logger = $logger;
        $pre           = __METHOD__ . ' : ';
        $this->_logger->debug($pre . 'bof');

        $this->localeResolver         = $localeResolver;
        $this->config                 = $configFactory->create();
        $this->currentCustomer        = $currentCustomer;
        $this->fortisHelper           = $fortisHelper;
        $this->paymentHelper          = $paymentHelper;
        $this->assetRepo              = $assetRepo;
        $this->urlBuilder             = $urlBuilder;
        $this->request                = $request;
        $this->paymentTokenManagement = $paymentTokenManagement;

        foreach ($this->methodCodes as $code) {
            $this->methods[$code] = $this->paymentHelper->getMethodInstance($code);
        }

        $this->_logger->debug($pre . 'eof and this  methods has : ', $this->methods);
    }

    /**
     * Get Config
     *
     * {@inheritdoc}
     */
    public function getConfig()
    {
        $pre       = __METHOD__ . ' : ';
        $cards     = [];
        $cardCount = 0;
        if ($this->currentCustomer->getCustomerId()) {
            $customerId = $this->currentCustomer->getCustomerId();
            $cardList   = $this->paymentTokenManagement->getListByCustomerId($customerId);
            foreach ($cardList as $card) {
                if ($card['is_active'] == 1 && $card['is_visible'] == 1) {
                    $cardDetails = json_decode($card['details']);
                    if ($cardDetails->type === 'ach') {
                        $text = "Use ACH account ending " . $cardDetails->maskedCC;
                    } else {
                        $text = "Use credit card ending in " . $cardDetails->maskedCC;
                    }
                    $cardDetail = [
                        'masked_cc' => $cardDetails->maskedCC,
                        'token'     => $card['public_hash'],
                        'card_type' => $cardDetails->type,
                        'text'      => $text,
                    ];
                    $cards[]    = $cardDetail;
                    $cardCount++;
                }
            }
            $isVault = $this->config->isVault();
        } else {
            $isVault = 0;
        }

        $this->_logger->debug($pre . 'bof');
        $fortisConfig = [
            'payment' => [
                'fortis' => [
                    'paymentAcceptanceMarkSrc'  => $this->config->getPaymentMarkImageUrl(),
                    'paymentAcceptanceMarkHref' => $this->config->getPaymentMarkWhatIsFortis(),
                    'isVault'                   => $isVault,
                    'saved_card_data'           => json_encode($cards),
                    'card_count'                => $cardCount,
                    'redirectUrl'               => $this->urlBuilder->getRedirectUrl('fortis/redirect'),
                    'achIsEnabled'              => $this->config->achIsActive(),
                    'isCheckoutIframe'          => $this->config->isCheckoutIframe(),
                    'isSingleView'              => $this->config->isSingleView(),
                ],
            ],
        ];

        $this->_logger->debug($pre . 'eof', $fortisConfig);

        return $fortisConfig;
    }

    /**
     * Retrieve url of a view file
     *
     * @param string $fileId
     * @param array $params
     *
     * @return string
     */
    public function getViewFileUrl($fileId, array $params = [])
    {
        try {
            $params = array_merge(['_secure' => $this->request->isSecure()], $params);

            return $this->assetRepo->getUrlWithParams($fileId, $params);
        } catch (LocalizedException $e) {
            $this->_logger->critical($e);

            return $this->urlBuilder->getUrl('', ['_direct' => 'core/index/notFound']);
        }
    }

    /**
     * Return redirect URL for method
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function getMethodRedirectUrl($code)
    {
        $pre = __METHOD__ . ' : ';
        $this->_logger->debug($pre . 'bof');

        $methodUrl = $this->methods[$code]->getCheckoutRedirectUrl();

        $this->_logger->debug($pre . 'eof');

        return $methodUrl;
    }

    /**
     * Return billing agreement code for method
     *
     * @param string $code
     *
     * @return null|string
     */
    protected function getBillingAgreementCode($code)
    {
        $pre = __METHOD__ . ' : ';
        $this->_logger->debug($pre . 'bof');

        $customerId = $this->currentCustomer->getCustomerId();
        $this->config->setMethod($code);

        $this->_logger->debug($pre . 'eof');

        // Always return null
        return $this->fortisHelper->shouldAskToCreateBillingAgreement($this->config, $customerId);
    }
}
