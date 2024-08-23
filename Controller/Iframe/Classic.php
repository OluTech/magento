<?php

namespace Fortispay\Fortis\Controller\Iframe;

use Fortispay\Fortis\Controller\AbstractFortis;
use Fortispay\Fortis\Model\Fortis;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\OrderFactory;
use Ramsey\Uuid\Uuid;

class Classic extends AbstractFortis implements HttpPostActionInterface
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

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        RawFactory $resultRawFactory,
        OrderFactory $orderFactory,
        Session $session,
        Fortis $paymentMethod
    ) {
        $this->pageFactory      = $pageFactory;
        $this->resultRawFactory = $resultRawFactory;
        $this->orderFactory     = $orderFactory;
        $this->checkoutSession  = $session;
        $this->paymentMethod    = $paymentMethod;
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
            $this->setMessage($e->getMessage());
            $this->checkoutSession->restoreQuote();

            return parent::_prepareLayout();
        } catch (\Exception $exception) {
            $this->_logger->error($exception->getMessage());
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
            view: 'default',
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
    public function getResponse()
    {
        // TODO: Implement getResponse() method.
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
