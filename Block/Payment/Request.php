<?php

namespace Fortispay\Fortis\Block\Payment;

use Fortispay\Fortis\Model\Fortis;
use Fortispay\Fortis\Model\FortisApi;
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
use Fortispay\Fortis\Model\Config;

class Request extends Template
{
    public const SECURE = ['_secure' => true];

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
    protected $reader;
    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @param Context $context
     * @param OrderFactory $orderFactory
     * @param Session $checkoutSession
     * @param ReadFactory $readFactory
     * @param Reader $reader
     * @param Fortis $paymentMethod
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
        array $data = []
    ){
        $this->_orderFactory    = $orderFactory;
        $this->_checkoutSession = $checkoutSession;
        parent::__construct($context, $data);
        $this->_isScopePrivate = true;
        $this->readFactory     = $readFactory;
        $this->reader          = $reader;
        $this->_paymentMethod  = $paymentMethod;
        $this->encryptor       = $encryptor;
        $this->messageManager  = $messageManager;
    }

    public function _prepareLayout(): Request
    {
        $order          = $this->_checkoutSession->getLastRealOrder();
        $orderData      = $order->getPayment()->getData();
        $additionalData = $orderData['additional_information'];

        $incrementId = $order->getIncrementId();
        $orderId     = $order->getId();

        $addressAll = $order->getBillingAddress();
        list($address, $country, $city, $postalCode, $regionCode) = $this->getAddresses($addressAll);

        $action = $this->_paymentMethod->getConfigData('order_intention');

        if ($action === 'sale') {
            $returnUrl = $this->_urlBuilder->getUrl(
                    'fortis/redirect/success',
                    self::SECURE
                ) . '?gid=' . $order->getRealOrderId();
        } elseif ($action === 'auth-only') {
            $returnUrl = $this->_urlBuilder->getUrl(
                    'fortis/redirect/authorise',
                    self::SECURE
                ) . '?gid=' . $order->getRealOrderId();
        }

        $vaultHash = $additionalData['fortis-vault-method'] ?? '';
        if (strlen($vaultHash) > 10) {
            // Have a vaulted card transaction
            $paymentTokenManagementInterface = $this->_paymentMethod->getPaymentTokenManagement();
            $cardData                        = $paymentTokenManagementInterface->getByPublicHash(
                $vaultHash,
                $order->getCustomerId()
            );
            $gatewayToken                    = $cardData->getGatewayToken();
            // Do the tokenised card transaction
            try {
                $api = new FortisApi($this->_paymentMethod->getConfigData('fortis_environment'));

                $user_id              = $this->encryptor->decrypt($this->_paymentMethod->getConfigData('user_id'));
                $user_api_key         = $this->encryptor->decrypt($this->_paymentMethod->getConfigData('user_api_key'));
                $productTransactionId = $this->encryptor->decrypt(
                    $this->_paymentMethod->getConfigData('product_transaction_id')
                );
                $intentData           = [
                    'transaction_amount' => (int)($order->getTotalDue() * 100),
                    'token_id'           => $gatewayToken,
                    'description'        => $incrementId,
                ];
                if ($productTransactionId
                    && preg_match(
                        '/^(([0-9a-fA-F]{24})|(([0-9a-fA-F]{8})(([0-9a-fA-F]{4}){3})([0-9a-fA-F]{12})))$/',
                        $productTransactionId
                    ) === 1) {
                    $intentData['product_transaction_id'] = $productTransactionId;
                }

                $transactionResult = $api->doTokenisedTransaction($intentData, $user_id, $user_api_key);
                $transactionResult = json_decode($transactionResult);
                if (
                    strpos($transactionResult->type ?? '', 'Error') !== false
                    || isset($transactionResult->errors)
                ) {
                    throw new LocalizedException(__('Error: Please use a different saved card or a new card.'));
                }
                $returnUrl .= '&tid=' . $transactionResult->data->id;
                header("Location: $returnUrl");
                exit;
            } catch (LocalizedException $e) {
                $this->_logger->error($e->getMessage());
                $this->messageManager->addExceptionMessage($e, $e->getMessage());
                $this->setMessage($e->getMessage());
                $this->_checkoutSession->restoreQuote();

                return parent::_prepareLayout();
            } catch (\Exception $exception) {
                echo $exception;
            }
        }

        $enableVaultForOrder = ((int)($additionalData['fortis-vault-method'] ?? 0)) === 1;

        if ($vaultHash === 'new-save') {
            $enableVaultForOrder = true;
        }

        try {
            $config = $this->_paymentMethod->getFortisOrderToken($enableVaultForOrder);
        } catch (LocalizedException $e) {
            $this->_logger->error($e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
            $this->setMessage($e->getMessage());
            $this->_checkoutSession->restoreQuote();

            return parent::_prepareLayout();
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
        }
        $client_token            = $config['token'];
        $main_options            = $config['options']['main_options'];
        $floatingLabels          = (int)$main_options['floatingLabels'] === 1 ? 'true' : 'false';
        $showValidationAnimation = (int)$main_options['showValidationAnimation'] === 1 ? 'true' : 'false';
        $appearance_options      = $config['options']['appearance_options'];
        $redirectUrl             = $config['redirectUrl'];

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
                // {name: 'order_number', required: true, readOnly: true, value: `$orderId`, hidden: true},
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
     * @param \Magento\Sales\Model\Order\Address $addressAll
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
