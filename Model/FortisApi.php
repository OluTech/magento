<?php

namespace Fortispay\Fortis\Model;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use StdClass;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\Url\DecoderInterface;

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

    /**
     * @param Config $config
     * @param DecoderInterface $decoder
     * @param ClientInterface $httpClient
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        Config $config,
        DecoderInterface $decoder,
        ClientInterface $httpClient,
        UrlInterface $urlBuilder
    ) {
        $this->config     = $config;
        $this->decoder    = $decoder;
        $this->httpClient = $httpClient;
        $this->urlBuilder = $urlBuilder;

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
        $this->httpClient->setHeaders($this->createHeaders($user_id, $user_api_key));
        $this->httpClient->setOption(CURLOPT_RETURNTRANSFER, true);
        $this->httpClient->setOption(CURLOPT_MAXREDIRS, 10);
        $this->httpClient->setOption(CURLOPT_FOLLOWLOCATION, true);
        $this->httpClient->setOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        if (isset($data['transactionId'])) {
            unset($data['transactionId']);
        }

        $retryCount = 0;
        $maxRetries = 5;

        $response = null;

        while ($retryCount < $maxRetries) {
            try {
                if ($method === 'POST') {
                    $this->httpClient->post($url, $data);
                } elseif ($method === 'GET') {
                    $this->httpClient->get($url);
                } elseif ($method === 'DELETE' || $method === 'PUT' || $method === 'PATCH') {
                    $this->httpClient->setOption(CURLOPT_CUSTOMREQUEST, $method);
                    $this->httpClient->post($url, $data);
                }

                $response = $this->httpClient->getBody();
                if ($this->httpClient->getStatus() >= 200 && $this->httpClient->getStatus() < 300) {
                    return $response;
                }

                $retryCount++;
            } catch (Exception $e) {
                $retryCount++;
                if ($retryCount >= $maxRetries) {
                    throw new LocalizedException(__("API Request failed: " . $e->getMessage()));
                }
            }
        }

        $response = json_decode($response);
        if ($response?->type === 'Error') {
            $errorStr = '';

            if (isset($response->meta->errors)) {
                foreach ($response->meta->errors as $key => $error) {
                    $errorStr .= "$error[0]\n";
                }
            } elseif (isset($response->meta->details)) {
                foreach ($response->meta->details as $key => $error) {
                    $errorStr .= "$error->message\n";
                }
            } elseif (isset($response->meta)) {
                $errorStr = $response->meta->message;
            } elseif (isset($response->detail)) {
                $errorStr = $response->detail;
            }

            throw new LocalizedException(new Phrase($errorStr));
        }

        throw new LocalizedException(__("API Request failed after {$maxRetries} attempts."));
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

    public function getClientToken(array $intentData, string $user_id, string $user_api_key): string
    {
        $response        = $this->makeApiRequest(
            '/v1/elements/transaction/intention',
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

    public function getTransaction(string $transactionId, string $user_id, string $user_api_key): stdClass
    {
        $response = $this->makeApiRequest("/v1/transactions/{$transactionId}", $user_id, $user_api_key, [], 'GET');

        return json_decode($response);
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
            foreach ($decodedResponse->meta->errors as $key => $error) {
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

        $endpoint   = "/v1/transactions/$transactionId/level3/visa";
        $level3Data = [
            'destination_country_code' => $destination_country_code,
            'freight_amount'           => $order->getShippingAmount(),
            'shipfrom_zip_code'        => $storeManager->getStore()->getCode(),
            'shipto_zip_code'          => $customerAddress->getPostcode(),
            'tax_amount'               => $order->getTaxAmount(),
            'order_date'               => $orderDate,
        ];

        $lineItems = [];
        foreach ($order->getAllItems() as $item) {
            $product  = $item->getProduct();
            $unitCost = $product->getPrice();
            $lineItem = [
                'description'    => $item->getName(),
                'commodity_code' => $product->getCustomAttribute('commodity_code')?->getValue() ?? '',
                'product_code'   => $item->getSku(),
                'unit_code'      => $product->getCustomAttribute('unit_code')?->getValue() ?? '',
                'unit_cost'      => $unitCost,
                'quantity'       => $item->getQtyOrdered(),
                'tax_amount'     => $item->getTaxAmount(),
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

        $endpoint   = "/v1/transactions/$transactionId/level3/master-card";
        $level3Data = [
            'destination_country_code' => $destination_country_code,
            'freight_amount'           => $order->getShippingAmount(),
            'shipfrom_zip_code'        => $storeManager->getStore()->getCode(),
            'shipto_zip_code'          => $customerAddress->getPostcode(),
            'tax_amount'               => $order->getTaxAmount(),
        ];

        $lineItems = [];
        foreach ($order->getAllItems() as $item) {
            $product  = $item->getProduct();
            $unitCost = $product->getPrice();
            $lineItem = [
                'description'  => $item->getName(),
                'product_code' => $item->getSku(),
                'unit_code'    => $product->getCustomAttribute('unit_code')?->getValue() ?? '',
                'unit_cost'    => $unitCost,
                'quantity'     => $item->getQtyOrdered(),
                'tax_amount'   => $item->getTaxAmount(),
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
}
