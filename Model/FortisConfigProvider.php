<?php

namespace Fortispay\Fortis\Model;

use Fortispay\Fortis\Service\CheckoutProcessor;
use Fortispay\Fortis\Service\FortisMethodService;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Vault\Model\PaymentTokenManagement;
use Psr\Log\LoggerInterface;

class FortisConfigProvider implements ConfigProviderInterface
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var CurrentCustomer
     */
    private CurrentCustomer $currentCustomer;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var Repository
     */
    private Repository $assetRepo;

    /**
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var PaymentTokenManagement
     */
    private PaymentTokenManagement $paymentTokenManagement;
    private FortisMethodService $fortisMethodService;

    /**
     * Construct
     *
     * @param LoggerInterface $logger
     * @param ConfigFactory $configFactory
     * @param CurrentCustomer $currentCustomer
     * @param Repository $assetRepo
     * @param UrlInterface $urlBuilder
     * @param RequestInterface $request
     * @param PaymentTokenManagement $paymentTokenManagement
     * @param FortisMethodService $fortisMethodService
     */
    public function __construct(
        LoggerInterface $logger,
        ConfigFactory $configFactory,
        CurrentCustomer $currentCustomer,
        Repository $assetRepo,
        UrlInterface $urlBuilder,
        RequestInterface $request,
        PaymentTokenManagement $paymentTokenManagement,
        FortisMethodService $fortisMethodService
    ) {
        $this->logger = $logger;
        $pre          = __METHOD__ . ' : ';
        $this->logger->debug($pre . 'bof');

        $this->config                 = $configFactory->create();
        $this->currentCustomer        = $currentCustomer;
        $this->assetRepo              = $assetRepo;
        $this->urlBuilder             = $urlBuilder;
        $this->request                = $request;
        $this->paymentTokenManagement = $paymentTokenManagement;
        $this->fortisMethodService    = $fortisMethodService;
    }

    /**
     * Get Config
     *
     * {@inheritdoc}
     * @throws LocalizedException
     */
    public function getConfig(): array
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
                        'id'        => $card['entity_id'],
                    ];
                    $cards[]    = $cardDetail;
                    $cardCount++;
                }
            }
            $isVault = $this->config->isVault();
        } else {
            $isVault = 0;
        }

        $this->logger->debug($pre . 'bof');
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
                    'placeOrderBtnText'         => $this->config->getPlaceOrderBtnText(),
                    'surchargeDisclaimer'       => FortisMethodService::FORTIS_SURCHARGE_DISCLAIMER,
                    'supportedCurrencies'       => $this->config->getSupportedCurrencies(),
                ]
            ]
        ];

        if ($this->config->getIntentionFlow() === 'ticket-intention') {
            try {
                $ticketIntentionToken = $this->fortisMethodService->getTicketIntentionToken();
            } catch (LocalizedException $e) {
                $this->logger->critical($e);
                $ticketIntentionToken = null;
            }

            $ticketIntentionData = $this->fortisMethodService->prepareTicketIntentionData();

            $fortisConfig['payment']['fortis'] =
                array_merge(
                    $fortisConfig['payment']['fortis'],
                    $ticketIntentionData,
                    ['ticketIntentionToken' => $ticketIntentionToken]
                );
        }

        $this->logger->debug($pre . 'eof', $fortisConfig);

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
    public function getViewFileUrl(string $fileId, array $params = []): string
    {
        try {
            $params = array_merge(['_secure' => $this->request->isSecure()], $params);

            return $this->assetRepo->getUrlWithParams($fileId, $params);
        } catch (LocalizedException $e) {
            $this->logger->critical($e);

            return $this->urlBuilder->getUrl('', ['_direct' => 'core/index/notFound']);
        }
    }
}
