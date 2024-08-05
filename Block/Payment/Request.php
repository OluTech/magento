<?php

namespace Fortispay\Fortis\Block\Payment;

use Exception;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\Fortis;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\OrderFactory;
use Magento\Framework\Controller\ResultFactory;
use Ramsey\Uuid\Uuid;

class Request extends Template
{
    /**
     * @var MessageManagerInterface
     */
    protected MessageManagerInterface $messageManager;

    /**
     * @var CheckoutSession $_checkoutSession
     */
    protected $checkoutSession;

    /**
     * @var Fortis $_paymentMethod
     */
    protected $_paymentMethod;

    /**
     * @var OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * @var ReadFactory $readFactory
     */
    protected $readFactory;

    /**
     * @var Reader $reader
     */
    protected Reader $reader;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @var ResultFactory $resultFactory
     */
    protected $resultFactory;

    private string $successURL;

    protected Config $fortisConfig;

    /**
     * Construct
     *
     * @param Context $context
     * @param OrderFactory $orderFactory
     * @param CheckoutSession $checkoutSession
     * @param ReadFactory $readFactory
     * @param Reader $reader
     * @param Fortis $paymentMethod
     * @param EncryptorInterface $encryptor
     * @param MessageManagerInterface $messageManager
     * @param ResultFactory $resultFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        Session $checkoutSession,
        ReadFactory $readFactory,
        Reader $reader,
        Fortis $paymentMethod,
        EncryptorInterface $encryptor,
        MessageManagerInterface $messageManager,
        ResultFactory $resultFactory,
        Config $fortisConfig,
        array $data = []
    ) {
        $this->_orderFactory    = $orderFactory;
        $this->_checkoutSession = $checkoutSession;
        parent::__construct($context, $data);
        $this->_isScopePrivate = true;
        $this->readFactory     = $readFactory;
        $this->reader          = $reader;
        $this->_paymentMethod  = $paymentMethod;
        $this->encryptor       = $encryptor;
        $this->messageManager  = $messageManager;
        $this->resultFactory   = $resultFactory;
        $this->fortisConfig    = $fortisConfig;
    }

    public function _prepareLayout()
    {
        $order          = $this->_checkoutSession->getLastRealOrder();
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

        $achEnabled = $this->fortisConfig->achIsActive();

        try {
            $config = $this->_paymentMethod->getFortisOrderToken($enableVaultForOrder);
        } catch (LocalizedException $e) {
            $this->_logger->error($e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
            $this->setMessage($e->getMessage());
            $this->_checkoutSession->restoreQuote();

            return parent::_prepareLayout();
        } catch (Exception $exception) {
            Log::error($exception->getMessage());
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

        $submit = <<<SUBMIT
<script>
    const fortisDiv = document.createElement('div');
    fortisDiv.id = 'fortis_payment327';
    document.querySelector('div.main').append(fortisDiv);
</script>
    <script>
    require(['fortis-commerce'], function(Commerce) {
      setTimeout(() => {
         const elements = new Commerce.elements('$client_token');
         console.log(elements);
        elements.create({
            container: '#fortis_payment327',
            theme: '$main_options[theme]',
            environment: '$main_options[environment]',
            floatingLabels: $floatingLabels,
            showValidationAnimation: $showValidationAnimation,
            showReceipt: false,
            digitalWallets: $digitalWalletsValue,
SUBMIT;
        if (!$achEnabled) {
            $submit .= "view: 'default',";
        }
        $submit .= <<<SUBMIT
            appearance: {
            colorButtonSelectedBackground: `$appearance_options[colorButtonSelectedBackground]`,
            colorButtonSelectedText: `$appearance_options[colorButtonSelectedText]`,
            colorButtonActionBackground: `$appearance_options[colorButtonActionBackground]`,
            colorButtonActionText: `$appearance_options[colorButtonActionText]`,
            colorButtonBackground: `$appearance_options[colorButtonBackground]`,
            colorButtonText: `$appearance_options[colorButtonText]`,
            colorFieldBackground: `$appearance_options[colorFieldBackground]`,
            colorFieldBorder: `$appearance_options[colorFieldBorder]`,
            colorText: `$appearance_options[colorText]`,
            colorLink: `$appearance_options[colorLink]`,
            fontSize: `$appearance_options[fontSize]`,
            marginSpacing: `$appearance_options[marginSpacing]`,
            borderRadius: `$appearance_options[borderRadius]`,
            },
            fields: {
              additional: [
                {name: 'description', required: true, value: `$incrementId`, hidden: true},
                {name: 'transaction_api_id', hidden: true, value: `$guid`},
              ],
              billing: [
SUBMIT;
        if ($address !== '') {
            $submit .= "{name: 'address', required: false, value: `$address`},";
        }
        if ($country !== '') {
            $submit .= "{name: 'country', required: false, value: `$country`},";
        }
        if ($city !== '') {
            $submit .= "{name: 'city', required: false, value: `$city`},";
        }
        if ($postalCode !== '') {
            $submit .= "{name: 'postal_code', required: false, value: `$postalCode`},";
        }
        if ($regionCode !== '') {
            $submit .= "{name: 'state', required: false, value: `$regionCode`},";
        }

        $submit .= <<<SUBMIT
              ]
            }
        });

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
          })
          if (response.status === 200) {
            const redirect = await response.json();
            setTimeout(() => {
                window.location.href = redirect.redirectTo;
            }, 2000);
          } else {

          }
        })

        elements.on('error', (error) => {
          console.log(error);
        })
    })
      }, 1000);

    </script>
</body>
SUBMIT;

        $this->setMessage('Redirecting to Fortis')
             ->setId('fortis_checkout')
             ->setName('fortis_checkout')
             ->setFormMethod('POST')
             ->setFormData($this->_paymentMethod->getStandardCheckoutFormFields())
             ->setSubmitForm($submit);

        return parent::_prepareLayout();
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
