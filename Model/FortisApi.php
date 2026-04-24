<?php

namespace Fortispay\Fortis\Model;

use Exception;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Url\DecoderInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use StdClass;

class FortisApi
{
    public const DEVELOPER_ID_SANDBOX = 'Mgv2TEST';
    public const DEVELOPER_ID         = 'Mgv24PRD';
    public const FORTIS_API_SANDBOX   = "https://api.sandbox.fortis.tech";
    public const FORTIS_API           = "https://api.fortis.tech";

    private string $developerId;
    private string $fortisApi;
    private Config $config;
    private DecoderInterface $decoder;
    private ClientInterface $httpClient;
    private UrlInterface $urlBuilder;
    private \Psr\Log\LoggerInterface $logger;

    /**
     * @param Config $config
     * @param DecoderInterface $decoder
     * @param ClientInterface $httpClient
     * @param UrlInterface $urlBuilder
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        DecoderInterface $decoder,
        ClientInterface $httpClient,
        UrlInterface $urlBuilder,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->config     = $config;
        $this->decoder    = $decoder;
        $this->httpClient = $httpClient;
        $this->urlBuilder = $urlBuilder;
        $this->logger     = $logger;

        $this->initializeApiSettings();
    }

    private function initializeApiSettings(): void
    {
        if ($this->config->environment() === 'production') {
            $this->developerId = self::DEVELOPER_ID;
            $this->fortisApi   = self::FORTIS_API;
        } else {
            $this->developerId = self::DEVELOPER_ID_SANDBOX;
            $this->fortisApi   = self::FORTIS_API_SANDBOX;
        }
    }

    /**
     * Generic method for making an API request
     *
     * @param string $endpoint
     * @param string $user_id
     * @param string $user_api_key
     * @param array $data
     * @param string $method
     *
     * @return string|null
     * @throws LocalizedException
     */
    private function makeApiRequest(
        string $endpoint,
        string $user_id,
        string $user_api_key,
        array $data = [],
        string $method = 'POST'
    ): ?string {
        $url = $this->fortisApi . $endpoint;

        $this->httpClient->setTimeout(30);

        $headers = $this->createHeaders($user_id, $user_api_key);
        $this->httpClient->setHeaders($headers);

        $this->httpClient->setOption(CURLOPT_RETURNTRANSFER, true);
        $this->httpClient->setOption(CURLOPT_MAXREDIRS, 10);
        $this->httpClient->setOption(CURLOPT_FOLLOWLOCATION, true);
        $this->httpClient->setOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        if (isset($data['transactionId'])) {
            unset($data['transactionId']);
        }

        $retryCount = 0;
        $maxRetries = 5;
        $response   = null;

        $encodedData = !empty($data) ? json_encode($data) : '';

        while ($retryCount < $maxRetries) {
            try {
                if ($method === 'POST') {
                    $this->httpClient->setOption(CURLOPT_CUSTOMREQUEST, 'POST');
                    $this->httpClient->post($url, $encodedData);
                } elseif ($method === 'GET') {
                    $this->httpClient->setOption(CURLOPT_CUSTOMREQUEST, 'GET');
                    $this->httpClient->get($url);
                } elseif ($method === 'DELETE' || $method === 'PUT' || $method === 'PATCH') {
                    $this->httpClient->setOption(CURLOPT_CUSTOMREQUEST, $method);
                    $this->httpClient->post($url, $encodedData);
                }

                $response   = $this->httpClient->getBody();
                $statusCode = $this->httpClient->getStatus();

                if ($statusCode >= 200 && $statusCode < 300) {
                    return $response;
                }

                if ($statusCode >= 400 && $statusCode < 500) {
                    break;
                }

                $retryCount++;
            } catch (Exception $e) {
                $retryCount++;
                if ($retryCount >= $maxRetries) {
                    throw new LocalizedException(__("API Request failed: " . $e->getMessage()));
                }
            }
        }

        // If we get here, all retries failed
        $response = json_decode($response);
        if (isset($response->type) && $response->type === 'Error') {
            $errorStr = '';

            if (isset($response->meta->errors)) {
                foreach ($response->meta->errors as $error) {
                    $errorStr .= "$error[0]\n";
                }
            } elseif (isset($response->meta->details)) {
                foreach ($response->meta->details as $error) {
                    $errorStr .= "$error->message\n";
                }
            } elseif (isset($response->meta)) {
                $errorStr = $response->meta->message;
            } elseif (isset($response->detail)) {
                $errorStr = $response->detail;
            }

            throw new LocalizedException(new Phrase($errorStr));
        }

        throw new LocalizedException(
            __("API Request failed after {$maxRetries} attempts. " . json_encode($response))
        );
    }

    /**
     * Create headers for API request
     *
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return array
     */
    private function createHeaders(string $user_id, string $user_api_key): array
    {
        return [
            "Content-Type" => "application/json",
            "user-id"      => $user_id,
            "user-api-key" => $user_api_key,
            "developer-id" => $this->developerId,
        ];
    }

    /**
     * @throws LocalizedException
     */
    public function getClientToken(
        array $intentData,
        string $user_id,
        string $user_api_key,
        bool $isTicketIntention = false
    ): string {
        $endpoint = $isTicketIntention ? '/v1/elements/ticket/intention' : '/v1/elements/transaction/intention';

        $response        = $this->makeApiRequest(
            $endpoint,
            $user_id,
            $user_api_key,
            $intentData
        );
        $decodedResponse = json_decode($response);

        if (isset($decodedResponse->data->client_token)) {
            return $decodedResponse->data->client_token;
        }

        throw new LocalizedException(__("Invalid response from API"));
    }

    /**
     * @throws LocalizedException
     */
    public function ccSaleTicket(array $intentData, string $user_id, string $user_api_key): string
    {
        return $this->makeApiRequest('/v1/transactions/cc/sale/ticket', $user_id, $user_api_key, $intentData);
    }

    /**
     * @throws LocalizedException
     */
    public function ccAuthOnlyTicket(array $intentData, string $user_id, string $user_api_key): string
    {
        return $this->makeApiRequest('/v1/transactions/cc/auth-only/ticket', $user_id, $user_api_key, $intentData);
    }

    public function getTransaction(string $transactionId, string $user_id, string $user_api_key): stdClass
    {
        $response = $this->makeApiRequest(
            "/v1/transactions/{$transactionId}?expand=surcharge,account_vault",
            $user_id,
            $user_api_key,
            [],
            'GET'
        );

        return json_decode($response);
    }

    /**
     * Update the transaction with a new description
     */
    public function patchTransactionDescription(string $transactionId, string $newDescription): ?string
    {
        $user_id      = $this->config->userId();
        $user_api_key = $this->config->userApiKey();
        $endpoint     = "/v1/transactions/$transactionId";
        $payload      = ['description' => $newDescription];

        return $this->makeApiRequest($endpoint, $user_id, $user_api_key, $payload, 'PATCH');
    }

    public function doTokenisedTransaction(array $intentData, string $user_id, string $user_api_key): string
    {
        return $this->makeApiRequest('/v1/transactions/cc/sale/token', $user_id, $user_api_key, $intentData);
    }

    public function doAchTokenisedTransaction(array $intentData): string
    {
        $user_id      = $this->config->userId();
        $user_api_key = $this->config->userApiKey();

        return $this->makeApiRequest('/v1/transactions/ach/debit/token', $user_id, $user_api_key, $intentData);
    }

    public function refundTransactionAmount(array $intentData, string $user_id, string $user_api_key): bool|string|null
    {
        return $this->makeApiRequest(
            "/v1/transactions/{$intentData['transactionId']}/refund",
            $user_id,
            $user_api_key,
            $intentData,
            'PATCH'
        );
    }

    public function achRefundTransactionAmount(array $intentData): bool|string|null
    {
        $user_id      = $this->config->userId();
        $user_api_key = $this->config->userApiKey();

        return $this->makeApiRequest('/v1/transactions/ach/refund/prev-trxn', $user_id, $user_api_key, $intentData);
    }

    /**
     * @throws LocalizedException
     */
    public function calculateSurcharge(array $intentData): string
    {
        $user_id      = $this->config->userId();
        $user_api_key = $this->config->userApiKey();

        return $this->makeApiRequest("/v1/public/calculate-surcharge", $user_id, $user_api_key, $intentData);
    }

    /**
     * Void Auth Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return bool|string|null
     * @throws LocalizedException
     */
    public function voidAuthAmount(array $intentData, string $user_id, string $user_api_key): bool|string|null
    {
        return $this->makeApiRequest(
            "/v1/transactions/{$intentData['transactionId']}/void",
            $user_id,
            $user_api_key,
            $intentData,
            'PUT'
        );
    }

    /**
     * Auth Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return string|null
     * @throws LocalizedException
     */
    public function doAuthTransaction(array $intentData, string $user_id, string $user_api_key): ?string
    {
        $url = '/v1/transactions/cc/auth-only/token';

        // Using makeApiRequest to handle the API call
        return $this->makeApiRequest($url, $user_id, $user_api_key, $intentData, 'POST');
    }

    /**
     * Capture Auth Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return bool|string|null
     * @throws LocalizedException
     */
    public function doCompleteAuthTransaction(
        array $intentData,
        string $user_id,
        string $user_api_key
    ): bool|string|null {
        return $this->makeApiRequest(
            "/v1/transactions/{$intentData['transactionId']}/auth-complete",
            $user_id,
            $user_api_key,
            $intentData,
            'PATCH'
        );
    }

    public function doTokenCCDelete(array $intentData, string $user_id, string $user_api_key): string
    {
        return $this->makeApiRequest(
            "/v1/tokens/{$intentData['tokenId']}",
            $user_id,
            $user_api_key,
            $intentData,
            'DELETE'
        );
    }

    public function createTransactionWebhook(): string|null
    {
        $userId               = $this->config->userId();
        $userApiKey           = $this->config->userApiKey();
        $locationId           = $this->config->achLocationId();
        $achEnabled           = $this->config->achIsActive();
        $productTransactionId = $this->config->achProductId();

        if (!$achEnabled) {
            return null;
        }

        $url        = '/v1/webhooks/transaction';
        $webhookUrl = $this->urlBuilder->getBaseUrl() . 'fortis/webhook/achhook';
        $intentData = [
            'is_active'              => true,
            'location_id'            => $locationId,
            'on_create'              => 0,
            'on_update'              => 1,
            'on_delete'              => 1,
            'product_transaction_id' => $productTransactionId,
            'number_of_attempts'     => 1,
            'url'                    => $webhookUrl,
        ];

        $response        = $this->makeApiRequest($url, $userId, $userApiKey, $intentData, 'POST');
        $decodedResponse = json_decode($response);

        if ($decodedResponse->type === 'Error') {
            $errorStr = '';
            foreach ($decodedResponse->meta->errors as $error) {
                $errorStr .= "$error[0]\n";
            }
            throw new LocalizedException(new Phrase($errorStr));
        }

        return $decodedResponse->data->id ?? null;
    }

    public function deleteTransactionWebhook(string $achWebhookId): void
    {
        $userId     = $this->config->userId();
        $userApiKey = $this->config->userApiKey();

        $url      = "/v1/webhooks/$achWebhookId";
        $response = $this->makeApiRequest($url, $userId, $userApiKey, [], 'DELETE');

        if ($response && json_decode($response)->type === 'Error') {
            throw new LocalizedException(json_decode($response)->title);
        }
    }

    /**
     * @param Order $order
     * @param StoreManagerInterface $storeManager
     * @param CountryFactory $countryFactory
     * @param string $transactionId
     *
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function createVisaLevel3Entry(
        Order $order,
        StoreManagerInterface $storeManager,
        CountryFactory $countryFactory,
        string $transactionId
    ): void {
        $userId     = $this->config->userId();
        $userApiKey = $this->config->userApiKey();

        $customerAddress = $order->getShippingAddress();
        if (!$customerAddress) {
            $customerAddress = $order->getBillingAddress();
        }
        $countryId                = $customerAddress->getCountryId();
        $country                  = $countryFactory->create()->loadByCode($countryId);
        $destination_country_code = $country->getData('iso_numeric') ?? '840';

        $orderDate = $order->getCreatedAt();
        $orderDate = str_replace('-', '', substr($orderDate, 2, 8));

        $endpoint    = "/v1/transactions/$transactionId/level3/visa";
        $shipFromZip = $storeManager->getStore()->getConfig('shipping/origin/postcode') ?? '';
        $level3Data  = [
            'destination_country_code' => $destination_country_code,
            'freight_amount'           => (int)bcmul((string)($order->getShippingAmount() ?? 0), '100', 0),
            'shipfrom_zip_code'        => $shipFromZip,
            'shipto_zip_code'          => $customerAddress->getPostcode() ?? '',
            'tax_amount'               => (int)bcmul((string)($order->getTaxAmount() ?? 0), '100', 0),
            'order_date'               => $orderDate,
        ];

        $lineItems = [];
        foreach ($order->getAllItems() as $item) {
            $product  = $item->getProduct();
            $unitCost = (int)bcmul((string)$product->getPrice(), '100', 0);
            $lineItem = [
                'description'    => $item->getName(),
                'commodity_code' => $product->getCustomAttribute('commodity_code')?->getValue() ?: '0',
                'product_code'   => $item->getSku(),
                'unit_code'      => $product->getCustomAttribute('unit_code')?->getValue() ?: 'EA',
                'unit_cost'      => $unitCost,
                'quantity'       => (int)$item->getQtyOrdered(),
                'tax_amount'     => (int)bcmul((string)$item->getTaxAmount(), '100', 0),
            ];

            $lineItems[] = $lineItem;
        }
        $level3Data['line_items'] = $lineItems;
        $data                     = ['level3_data' => $level3Data];

        $response        = $this->makeApiRequest($endpoint, $userId, $userApiKey, $data);
        $responseDecoded = json_decode($response);

        if (isset($responseDecoded->type) && $responseDecoded->type === 'Error') {
            throw new LocalizedException(__('Level 3 creation error: ' . $responseDecoded->detail));
        }
    }

    /**
     * @param Order $order
     * @param StoreManagerInterface $storeManager
     * @param CountryFactory $countryFactory
     * @param string $transactionId
     *
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function createMcLevel3Entry(
        Order $order,
        StoreManagerInterface $storeManager,
        CountryFactory $countryFactory,
        string $transactionId
    ): void {
        $userId     = $this->config->userId();
        $userApiKey = $this->config->userApiKey();

        $customerAddress = $order->getShippingAddress();
        if (!$customerAddress) {
            $customerAddress = $order->getBillingAddress();
        }
        $countryId                = $customerAddress->getCountryId();
        $country                  = $countryFactory->create()->loadByCode($countryId);
        $destination_country_code = $country->getData('iso_numeric') ?? '840';

        $endpoint    = "/v1/transactions/$transactionId/level3/master-card";
        $shipFromZip = $storeManager->getStore()->getConfig('shipping/origin/postcode') ?? '';
        $level3Data  = [
            'destination_country_code' => $destination_country_code,
            'freight_amount'           => (int)bcmul((string)$order->getShippingAmount(), '100', 0),
            'shipfrom_zip_code'        => $shipFromZip,
            'shipto_zip_code'          => $customerAddress->getPostcode(),
            'tax_amount'               => (int)bcmul((string)$order->getTaxAmount(), '100', 0),
        ];

        $lineItems = [];
        foreach ($order->getAllItems() as $item) {
            $product  = $item->getProduct();
            $unitCost = (int)bcmul((string)$product->getPrice(), '100', 0);
            $lineItem = [
                'description'      => $item->getName(),
                'product_code'     => $item->getSku(),
                'unit_code'        => $product->getCustomAttribute('unit_code')?->getValue() ?: 'EA',
                'unit_cost'        => $unitCost,
                'quantity'         => (int)$item->getQtyOrdered(),
                'tax_amount'       => (int)bcmul((string)$item->getTaxAmount(), '100', 0),
                'tax_type_id'      => '01',
                'tax_type_applied' => 'ST',
            ];

            $lineItems[] = $lineItem;
        }
        $level3Data['line_items'] = $lineItems;
        $data                     = ['level3_data' => $level3Data];

        $response        = $this->makeApiRequest($endpoint, $userId, $userApiKey, $data);
        $responseDecoded = json_decode($response);

        if (isset($responseDecoded->type) && $responseDecoded->type === 'Error') {
            throw new LocalizedException(__('Level 3 creation error: ' . $responseDecoded->detail));
        }
    }

    /**
     * Get Token Body
     *
     * @param string $token
     *
     * @return false|string
     */
    public function getTokenBody(string $token): bool|string
    {
        $parts = explode('.', $token);

        return $this->decoder->decode($parts[1] ?? []);
    }

    /**
     * Validate Product Transaction ID with currency against Fortis API
     * Uses transaction intention endpoint only
     *
     * @param string $productId
     * @param string $currency
     * @param string|null $userId Optional user ID for validation
     * @param string|null $userApiKey Optional user API key for validation
     * @return bool
     * @throws LocalizedException
     */
    public function validateProductIdCurrency(
        string $productId,
        string $currency,
        ?string $userId = null,
        ?string $userApiKey = null
    ): bool {
        $user_id      = $userId ?? $this->config->userId();
        $user_api_key = $userApiKey ?? $this->config->userApiKey();

        if (in_array($currency, ['USD', 'CAD'])) {
            return true;
        }

        $testIntentionData = [
            'action'        => 'sale',
            'methods'       => [
                [
                    'type'                   => 'cc',
                    'product_transaction_id' => $productId
                ]
            ],
            'amount'        => $this->getMinorUnitsTestAmount($currency),
            'currency_code' => $currency,
            'location_id'   => $this->config->achLocationId(),
        ];

        try {
            $clientToken = $this->getClientToken($testIntentionData, $user_id, $user_api_key);

            if (!empty($clientToken)) {
                return true;
            }
        } catch (LocalizedException $e) {
            $errorMessage = $e->getMessage();

            if (str_contains($errorMessage, 'methods[0].currency') && str_contains($errorMessage, 'not allowed')) {
                throw new LocalizedException(
                    __(
                        'API request structure error for Product Transaction ID %1. The currency field should not be inside the methods array. This indicates a configuration issue with the Fortis API integration.',
                        $productId
                    )
                );
            }

            if (str_contains($errorMessage, 'product_transaction_id') && str_contains($errorMessage, 'not found')) {
                throw new LocalizedException(
                    __(
                        'Product Transaction ID %1 was not found in your Fortis %2 account during currency validation. Please verify: 1) The Product Transaction ID exists in your Fortis dashboard, 2) It is configured for credit card processing, 3) It supports currency %3, 4) You are using the correct environment. Current API endpoint: %4',
                        $productId,
                        $this->config->environment(),
                        $currency,
                        $this->fortisApi
                    )
                );
            }

            if (str_contains($errorMessage, 'currency') && str_contains($errorMessage, 'not allowed')) {
                throw new LocalizedException(
                    __(
                        'Currency %1 is not supported for Product Transaction ID %2. Please contact Fortis to enable multicurrency support or verify the Product ID configuration.',
                        $currency,
                        $productId
                    )
                );
            }

            if (str_contains($errorMessage, '412') ||
                str_contains($errorMessage, 'Multi-currency not enabled') ||
                str_contains($errorMessage, 'not enabled on Product Transaction ID')) {
                throw new LocalizedException(
                    __(
                        'Multi-currency is not enabled for Product Transaction ID %1. Please contact Fortis to enable multicurrency support for currency %2. (Error 412)',
                        $productId,
                        $currency
                    )
                );
            }

            if (strpos($errorMessage, '400') !== false &&
                (strpos($errorMessage, 'currency') !== false || strpos($errorMessage, 'Missing currency') !== false)) {
                throw new LocalizedException(
                    __(
                        'Currency field is required for Product Transaction ID %1 when using %2. Please ensure multicurrency is properly configured.',
                        $productId,
                        $currency
                    )
                );
            }

            if (str_contains($errorMessage, 'Unsupported card type')) {
                throw new LocalizedException(
                    __(
                        'Some card types (e.g., Amex) may not be supported for currency %1. Please check with Fortis about card type restrictions.',
                        $currency
                    )
                );
            }

            throw new LocalizedException(
                __(
                    'Failed to validate Product ID %1 with currency %2. API Error: %3',
                    $productId,
                    $currency,
                    $errorMessage
                )
            );
        }

        return true;
    }

    /**
     * Get test amount in minor units based on currency decimal rules
     * Per Fortis Multicurrency Developer Guide
     *
     * @param string $currency
     * @return int
     */
    private function getMinorUnitsTestAmount(string $currency): int
    {
        $zeroDecimalCurrencies = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KRW', 'PYG', 'UGX', 'VND'];

        if (in_array($currency, $zeroDecimalCurrencies)) {
            return 100;
        }

        if ($currency === 'KWD') {
            return 1000;
        }


        return 100;
    }
}
