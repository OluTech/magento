<?php

namespace Fortispay\Fortis\Controller\Iframe;

use Fortispay\Fortis\Controller\AbstractFortis;
use Fortispay\Fortis\Helper\Data as FortisHelper;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\Fortis;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\DB\Transaction as DBTransaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
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
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Magento\Framework\App\ResponseInterface;

class Simple extends AbstractFortis implements HttpPostActionInterface
{
    protected $pageFactory;
    private RawFactory $resultRawFactory;
    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    private OrderFactory $orderFactory;
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private Session $checkoutSession;
    /**
     * @var \Fortispay\Fortis\Model\Fortis
     */
    private Fortis $paymentMethod;
    private ResponseInterface $response;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        RawFactory $resultRawFactory,
        OrderFactory $orderFactory,
        Session $session,
        Fortis $paymentMethod,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        Generic $fortisSession,
        Data $urlHelper,
        Url $customerUrl,
        LoggerInterface $logger,
        TransactionFactory $transactionFactory,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
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
        ResponseInterface $response
    ) {
        $this->pageFactory      = $pageFactory;
        $this->resultRawFactory = $resultRawFactory;
        $this->orderFactory     = $orderFactory;
        $this->checkoutSession  = $session;
        $this->paymentMethod    = $paymentMethod;
        $this->response         = $response;

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
            $countryCollectionFactory
        );
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $result = $this->resultRawFactory->create();

        $order          = $this->checkoutSession->getLastRealOrder();
        $orderData      = $order->getPayment()->getData();
        $additionalData = $orderData['additional_information'];

        $incrementId = $order->getIncrementId();
        $orderId     = $order->getId();

        $addressAll = $order->getBillingAddress();
        list($address, $country, $city, $postalCode, $regionCode) = $this->getAddresses($addressAll);

        $enableVaultForOrder = ((int)($additionalData['fortis-vault-method'] ?? 0)) === 1;

        $vaultHash = $additionalData['fortis-vault-method'] ?? '';

        if ($vaultHash === 'new-save') {
            $enableVaultForOrder = true;
        }

        try {
            $config = $this->paymentMethod->getFortisOrderToken($enableVaultForOrder);
        } catch (LocalizedException $e) {
            $this->_logger->error($e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
            $this->checkoutSession->restoreQuote();

            $this->getResponse()->setHeader('Content-Type', 'application/json');
            $this->getResponse()->setBody(json_encode(['success' => false, 'reason' => $e->getMessage()]));

            return $this->getResponse();
        }

        $client_token            = $config['token'];
        $main_options            = $config['options']['main_options'];
        $floatingLabels          = (int)$main_options['floatingLabels'] === 1 ? 'true' : 'false';
        $showValidationAnimation = (int)$main_options['showValidationAnimation'] === 1 ? 'true' : 'false';
        $appearance_options      = $config['options']['appearance_options'];
        $redirectUrl             = $config['redirectUrl'];
        $guid                    = strtoupper(Uuid::uuid4());
        $guid                    = str_replace('-', '', $guid);

        $digitalWallets = [];
        if ($config['googlepay']) {
            array_push($digitalWallets, 'GooglePay');
        }
        if ($config['applepay']) {
            array_push($digitalWallets, 'ApplePay');
        }
        $digitalWalletsValue = "['" . implode("', '", $digitalWallets) . "']";
        if ($digitalWalletsValue == "['']") {
            $digitalWalletsValue = '[]';
        }

        $submit = <<<CONTENT
</script>
    <script>
    require(['fortis-commerce'], function(Commerce) {
      setTimeout(() => {
         const elements = new Commerce.elements('$client_token');
         console.log(elements);
        elements.create({
            container: '#fortis-framed-2567',
            theme: '$main_options[theme]',
            environment: '$main_options[environment]',
            view: 'card-single-field',
            floatingLabels: $floatingLabels,
            hideAgreementCheckbox: false,
            hideTotal: false,
            showReceipt: false,
            digitalWallets: $digitalWalletsValue,
            fields: {
              additional: [
                {name: 'description', required: true, value: `$incrementId`, hidden: true},
                {name: 'transaction_api_id', hidden: true, value: `$guid`},
              ],
              billing: [
CONTENT;

        if ($postalCode !== '') {
            $submit .= "{name: 'postal_code', required: false, value: `$postalCode`},";
        }

        $submit .= <<<CONTENT
                ]
            }
        });

        elements.on(
          'ready',
          function () {
            jQuery('#fortis-saved_cards').hide();
          }
        );

        elements.on('paymentFinished', (result) => {
          if(result.status === 'approved') {
            console.log('approved');
          } else {
            console.log('failed');
          }
          console.log(result);
        });

        elements.on('done', async (result) => {
          console.log(result);
          // POST result to redirect endpoint
          const response = await fetch('$redirectUrl', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(result.data)
          });
          if (response.status === 200) {
            const redirect = await response.json();
            setTimeout(() => {
                window.location.href = redirect.redirectTo;
            }, 2000);
          } else {

          }
        });

        elements.on('error', (error) => {
          console.log(error);
        });
    });
      }, 1000);

    </script>
</body>
CONTENT;

        $result->setContents($submit);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Get Addresses
     *
     * @param Address $addressAll
     *
     * @return array
     */
    private function getAddresses(Address $addressAll): array
    {
        $address    = implode(', ', $addressAll->getStreet());
        $country    = $addressAll->getCountryId() ?? '';
        $city       = $addressAll->getCity() ?? '';
        $postalCode = $addressAll->getPostcode() ?? '';
        $regionCode = $addressAll->getRegionCode() ?? '';

        return [$address, $country, $city, $postalCode, $regionCode];
    }
}
