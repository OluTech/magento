<?php

namespace Fortispay\Fortis\Model\Payment;

use Exception;
use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\Fortis;
use Magento\Checkout\Model\Session;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order\Address;
use Ramsey\Uuid\Uuid;

class IFrameData
{
    protected Session $checkoutSession;
    protected Fortis $paymentMethod;
    protected Config $fortisConfig;
    protected ManagerInterface $messageManager;

    public function __construct(
        Session $checkoutSession,
        Fortis $paymentMethod,
        Config $fortisConfig,
        ManagerInterface $messageManager,
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->fortisConfig    = $fortisConfig;
        $this->paymentMethod   = $paymentMethod;
        $this->messageManager  = $messageManager;
    }

    /**
     * @return array|null
     */
    public function buildIFrameData(): ?array
    {
        $order          = $this->checkoutSession->getLastRealOrder();
        $orderData      = $order->getPayment()->getData();
        $additionalData = $orderData['additional_information'];

        $orderId = $order->getIncrementId();

        $addressAll = $order->getBillingAddress();
        list($address, $country, $city, $postalCode, $regionCode) = $this->getAddresses($addressAll);

        $enableVaultForOrder = ((int)($additionalData['fortis-vault-method'] ?? 0)) === 1;

        $vaultHash = $additionalData['fortis-vault-method'] ?? '';

        if ($vaultHash === 'new-save') {
            $enableVaultForOrder = true;
        }

        try {
            $config = $this->paymentMethod->getFortisOrderToken($enableVaultForOrder);
        } catch (Exception $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
            $this->checkoutSession->restoreQuote();

            return null;
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
            $digitalWallets[] = 'GooglePay';
        }
        if ($config['applepay']) {
            $digitalWallets[] = 'ApplePay';
        }

        return [
            'client_token'            => $client_token,
            'main_options'            => $main_options,
            'floatingLabels'          => $floatingLabels,
            'showValidationAnimation' => $showValidationAnimation,
            'appearance_options'      => $appearance_options,
            'redirectUrl'             => $redirectUrl,
            'orderId'                 => $orderId,
            'guid'                    => $guid,
            'digitalWallets'          => $digitalWallets,
            'billingFields'           => array_filter([
                                                          $address ? ['name'     => 'address',
                                                                      'required' => false,
                                                                      'value'    => $address
                                                          ] : null,
                                                          $country ? ['name'     => 'country',
                                                                      'required' => false,
                                                                      'value'    => $country
                                                          ] : null,
                                                          $city ? ['name'     => 'city',
                                                                   'required' => false,
                                                                   'value'    => $city
                                                          ] : null,
                                                          $postalCode ? ['name'     => 'postal_code',
                                                                         'required' => false,
                                                                         'value'    => $postalCode
                                                          ] : null,
                                                          $regionCode ? ['name'     => 'state',
                                                                         'required' => false,
                                                                         'value'    => $regionCode
                                                          ] : null
                                                      ])
        ];
    }

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
