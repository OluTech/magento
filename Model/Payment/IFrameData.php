<?php

namespace Fortispay\Fortis\Model\Payment;

use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order\Address;
use Ramsey\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Fortispay\Fortis\Service\FortisMethodService;

class IFrameData
{
    private Session $checkoutSession;
    private ManagerInterface $messageManager;
    private LoggerInterface $logger;
    private FortisMethodService $fortisMethodService;

    /**
     * @param Session $checkoutSession
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     * @param FortisMethodService $fortisMethodService
     */
    public function __construct(
        Session $checkoutSession,
        ManagerInterface $messageManager,
        LoggerInterface $logger,
        FortisMethodService $fortisMethodService
    ) {
        $this->checkoutSession     = $checkoutSession;
        $this->messageManager      = $messageManager;
        $this->logger              = $logger;
        $this->fortisMethodService = $fortisMethodService;
    }

    /**
     * @return array|null
     */
    public function buildIFrameData(): ?array
    {
        $order          = $this->checkoutSession->getLastRealOrder();
        $orderData      = $order->getPayment()->getData();
        $additionalData = $orderData['additional_information'];

        $orderId = $order->getId();

        $addressAll = $order->getBillingAddress();
        list($address, $country, $city, $postalCode, $regionCode) = $this->getAddresses($addressAll);

        $enableVaultForOrder = ((int)($additionalData['fortis-vault-method'] ?? 0)) === 1;

        $vaultHash = $additionalData['fortis-vault-method'] ?? '';

        if ($vaultHash === 'new-save') {
            $enableVaultForOrder = true;
        }

        try {
            $config = $this->fortisMethodService->getFortisOrderToken($enableVaultForOrder);
        } catch (Exception $e) {
            $this->checkoutSession->restoreQuote();
            $this->logger->error('Something went wrong while fetching the Fortis order token: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
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
            'success'                 => true,
            'client_token'            => $client_token,
            'main_options'            => $main_options,
            'floatingLabels'          => $floatingLabels,
            'showValidationAnimation' => $showValidationAnimation,
            'appearance_options'      => $appearance_options,
            'redirectUrl'             => $redirectUrl,
            'orderId'                 => $orderId,
            'incrementId'             => $order->getIncrementId(),
            'guid'                    => $guid,
            'digitalWallets'          => $digitalWallets,
            'billingFields'           => array_filter([
                                                          $address ? [
                                                              'name'     => 'address',
                                                              'required' => false,
                                                              'value'    => strlen($address) > 32 ? substr(
                                                                  $address,
                                                                  0,
                                                                  32
                                                              ) : $address
                                                          ] : null,
                                                          $country ? [
                                                              'name'     => 'country',
                                                              'required' => false,
                                                              'value'    => $country
                                                          ] : null,
                                                          $city ? [
                                                              'name'     => 'city',
                                                              'required' => false,
                                                              'value'    => $city
                                                          ] : null,
                                                          $postalCode ? [
                                                              'name'     => 'postal_code',
                                                              'required' => false,
                                                              'value'    => $postalCode
                                                          ] : null,
                                                          $regionCode ? [
                                                              'name'     => 'state',
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
