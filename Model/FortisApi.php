<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use stdClass;
use Magento\Directory\Model\CountryFactory;

class FortisApi
{
    public const DEVELOPER_ID_SANDBOX = 'Mgv2TEST';
    public const DEVELOPER_ID         = 'Mgv24PRD';
    public const FORTIS_API_SANDBOX   = "https://api.sandbox.fortis.tech";
    public const FORTIS_API           = "https://api.fortis.tech";
    /**
     * @var string
     */
    private string $developerId;
    /**
     * @var string
     */
    private string $fortisApi;
    private Config $config;
    private ?string $webhookUrl;

    /**
     * Construct
     *
     * @param \Fortispay\Fortis\Model\Config $config
     * @param string|null $url
     */
    public function __construct(
        Config $config,
        string $url = null
    ) {
        $this->config = $config;

        $environment = $this->config->environment();
        if ($environment === 'production') {
            $this->developerId = self::DEVELOPER_ID;
            $this->fortisApi   = self::FORTIS_API;
        } else {
            $this->developerId = self::DEVELOPER_ID_SANDBOX;
            $this->fortisApi   = self::FORTIS_API_SANDBOX;
        }
        $this->webhookUrl = $url;
    }

    /**
     * Get Client Token
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getClientToken(array $intentData, string $user_id, string $user_api_key): string
    {
        return $this->transactionIntention($intentData, $user_id, $user_api_key);
    }

    /**
     * Get Transaction
     *
     * @param string $transactionId
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return stdClass
     */
    public function getTransaction(string $transactionId, string $user_id, string $user_api_key): stdClass
    {
        return $this->transactionRetrieve($transactionId, $user_id, $user_api_key);
    }

    /**
     * Do Tokenised Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return string
     */
    public function doTokenisedTransaction(array $intentData, string $user_id, string $user_api_key): string
    {
        return $this->tokenisedTransaction($intentData, $user_id, $user_api_key);
    }

    /**
     * Do ACH Tokenised Transaction
     *
     * @param array $intentData
     *
     * @return string
     */
    public function doAchTokenisedTransaction(array $intentData): string
    {
        return $this->achTokenisedTransaction($intentData);
    }

    /**
     * Do Auth Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return string
     */
    public function doAuthTransaction(array $intentData, string $user_id, string $user_api_key): string
    {
        return $this->authTransaction($intentData, $user_id, $user_api_key);
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

        return base64_decode($parts[1] ?? []);
    }

    /**
     * Refund Transaction Amount
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return bool|string|null
     */
    public function refundTransactionAmount(array $intentData, string $user_id, string $user_api_key): bool|string|null
    {
        return $this->refundTransaction($intentData, $user_id, $user_api_key);
    }

    /**
     * Refund Transaction Amount - ACH transactions
     *
     * @param array $intentData
     *
     * @return bool|string|null
     */
    public function achRefundTransactionAmount(array $intentData): bool|string|null
    {
        return $this->achRefundTransaction($intentData);
    }

    /**
     * Refund Auth Amount
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return bool|string|null
     */
    public function refundAuthAmount(array $intentData, string $user_id, string $user_api_key): bool|string|null
    {
        return $this->voidAuthTransaction($intentData, $user_id, $user_api_key);
    }

    /**
     * Get Fortis Api
     *
     * @return string
     */
    public function getFortisApi(): string
    {
        return $this->fortisApi;
    }

    /**
     * Get Developer Id
     *
     * @return string
     */
    public function getDeveloperId(): string
    {
        return $this->developerId;
    }

    /**
     * Transaction Intention
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return string|null
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function transactionIntention(array $intentData, string $user_id, string $user_api_key): ?string
    {
        $developer_id = $this->developerId;
        $url          = $this->fortisApi . '/v1/elements/transaction/intention';
        $curl         = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => json_encode($intentData),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $user_id",
                    "user-api-key: $user_api_key",
                    "developer-id: $developer_id",
                ],
            ]
        );

        $cnt           = 0;
        $intentCreated = false;
        $curlError     = null;
        $response      = null;
        while (!$intentCreated && $cnt < 5) {
            $response     = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode !== 200) {
                $curlError = curl_error($curl);
                $cnt++;
            }
            $intentCreated = true;
        }

        if (!$intentCreated) {
            // Do something with this error
            return $curlError;
        }

        $response = json_decode($response);
        if (!isset($response->data)) {
            $statusCode = 'Unknown';
            $title      = 'Unknown';
            if (isset($response->statusCode)) {
                $statusCode = $response->statusCode;
            }
            if (isset($response->title)) {
                $title = $response->title;
            }
            throw new LocalizedException(__($statusCode . ': ' . $title));
        }

        return $response->data->client_token;
    }

    /**
     * Transaction Retrieve
     *
     * @param string $transactionId
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return stdClass
     */
    private function transactionRetrieve(string $transactionId, string $user_id, string $user_api_key): stdClass
    {
        $developer_id = $this->developerId;
        $url          = $this->fortisApi . "/v1/transactions/$transactionId";
        $curl         = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "GET",
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $user_id",
                    "user-api-key: $user_api_key",
                    "developer-id: $developer_id",
                ],
            ]
        );

        $cnt                  = 0;
        $transactionRetrieved = false;
        $curlError            = null;
        $response             = null;
        while (!$transactionRetrieved && $cnt < 5) {
            $response     = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode !== 200) {
                $curlError = curl_error($curl);
                $cnt++;
            }
            $transactionRetrieved = true;
        }

        if (!$transactionRetrieved) {
            // Do something with this error
            return $curlError;
        }

        return json_decode($response);
    }

    /**
     * Tokenised Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return string
     */
    private function tokenisedTransaction(array $intentData, string $user_id, string $user_api_key): string
    {
        $developer_id = $this->developerId;
        $url          = $this->fortisApi . '/v1/transactions/cc/sale/token';

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => json_encode($intentData),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $user_id",
                    "user-api-key: $user_api_key",
                    "developer-id: $developer_id",
                ],
            ]
        );

        $cnt           = 0;
        $intentCreated = false;
        $curlError     = null;
        $response      = null;
        while (!$intentCreated && $cnt < 5) {
            $response     = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode !== 201) {
                $curlError = curl_error($curl);
                $cnt++;
            }
            $intentCreated = true;
        }

        if (!$intentCreated) {
            // Do something with this error
            return $curlError;
        }

        return $response;
    }

    /**
     * @param array $intentData
     *
     * @return string
     */
    private function achTokenisedTransaction(array $intentData): string
    {
        $user_id      = $this->config->userId();
        $user_api_key = $this->config->userApiKey();
        $developer_id = $this->developerId;
        $url          = $this->fortisApi . '/v1/transactions/ach/debit/token';

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => json_encode($intentData),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $user_id",
                    "user-api-key: $user_api_key",
                    "developer-id: $developer_id",
                ],
            ]
        );

        $cnt           = 0;
        $intentCreated = false;
        $curlError     = null;
        $response      = null;
        while (!$intentCreated && $cnt < 5) {
            $response     = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode !== 201) {
                $curlError = curl_error($curl);
                $cnt++;
            }
            $intentCreated = true;
        }

        if (!$intentCreated) {
            // Do something with this error
            return $curlError;
        }

        return $response;
    }

    /**
     * Refund Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return bool|string|null
     */
    private function refundTransaction(array $intentData, string $user_id, string $user_api_key): bool|string|null
    {
        $developer_id = $this->developerId;
        $url          = $this->fortisApi . "/v1/transactions/$intentData[transactionId]/refund";

        unset($intentData['transactionId']);

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "PATCH",
                CURLOPT_POSTFIELDS     => json_encode($intentData),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $user_id",
                    "user-api-key: $user_api_key",
                    "developer-id: $developer_id",
                ],
            ]
        );

        $cnt           = 0;
        $intentCreated = false;
        $curlError     = null;
        $response      = null;
        while (!$intentCreated && $cnt < 5) {
            $response     = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode !== 200) {
                $curlError = curl_error($curl);
                $cnt++;
            }
            $intentCreated = true;
        }

        if (!$intentCreated) {
            // Do something with this error
            return $curlError;
        }

        return $response;
    }


    /**
     * Refund ACH Transaction
     *
     * @param array $intentData
     *
     * @return bool|string|null
     */
    private function achRefundTransaction(array $intentData)
    {
        $developer_id = $this->developerId;
        $user_id      = $this->config->userId();
        $user_api_key = $this->config->userApiKey();

        $url = $this->fortisApi . "/v1/transactions/ach/refund/prev-trxn";

        unset($intentData['transactionId']);

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => json_encode($intentData),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $user_id",
                    "user-api-key: $user_api_key",
                    "developer-id: $developer_id",
                ],
            ]
        );

        $cnt           = 0;
        $intentCreated = false;
        $curlError     = null;
        $response      = null;
        while (!$intentCreated && $cnt < 5) {
            $response     = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode !== 200) {
                $curlError = curl_error($curl);
                $cnt++;
            }
            $intentCreated = true;
        }

        if (!$intentCreated) {
            // Do something with this error
            return $curlError;
        }

        return $response;
    }

    /**
     * Void Auth Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return bool|string|null
     */
    private function voidAuthTransaction(array $intentData, string $user_id, string $user_api_key): bool|string|null
    {
        $developer_id = $this->developerId;
        $url          = $this->fortisApi . "/v1/transactions/$intentData[transactionId]/void";

        unset($intentData['transactionId']);

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "PUT",
                CURLOPT_POSTFIELDS     => json_encode($intentData),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $user_id",
                    "user-api-key: $user_api_key",
                    "developer-id: $developer_id",
                ],
            ]
        );

        $cnt           = 0;
        $intentCreated = false;
        $curlError     = null;
        $response      = null;
        while (!$intentCreated && $cnt < 5) {
            $response     = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode !== 200) {
                $curlError = curl_error($curl);
                $cnt++;
            }
            $intentCreated = true;
        }

        if (!$intentCreated) {
            // Do something with this error
            return $curlError;
        }

        return $response;
    }

    /**
     * Auth Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return bool|string|null
     */
    private function authTransaction(array $intentData, string $user_id, string $user_api_key): bool|string|null
    {
        $developer_id = $this->developerId;
        $url          = $this->fortisApi . "/v1/transactions/cc/auth-only/token";

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => json_encode($intentData),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $user_id",
                    "user-api-key: $user_api_key",
                    "developer-id: $developer_id",
                ],
            ]
        );

        $cnt           = 0;
        $intentCreated = false;
        $curlError     = null;
        $response      = null;
        while (!$intentCreated && $cnt < 5) {
            $response     = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode !== 200) {
                $curlError = curl_error($curl);
                $cnt++;
            }
            $intentCreated = true;
        }

        if (!$intentCreated) {
            // Do something with this error
            return $curlError;
        }

        return $response;
    }

    private function completeAuthTransaction($intentData, $user_id, $user_api_key): bool|string|null
    {
        $developer_id = $this->developerId;
        $url          = $this->fortisApi . "/v1/transactions/" . $intentData["transactionId"] . "/auth-complete";

        unset($intentData['transactionId']);

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "PATCH",
                CURLOPT_POSTFIELDS     => json_encode($intentData),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $user_id",
                    "user-api-key: $user_api_key",
                    "developer-id: $developer_id",
                ],
            ]
        );

        $cnt           = 0;
        $intentCreated = false;
        $curlError     = null;
        $response      = null;
        while (!$intentCreated && $cnt < 5) {
            $response     = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode !== 200) {
                $curlError = curl_error($curl);
                $cnt++;
            }
            $intentCreated = true;
        }

        if (!$intentCreated) {
            // Do something with this error
            return $curlError;
        }

        return $response;
    }

    /**
     * Do Complete Auth Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return string
     */
    public function doCompleteAuthTransaction(array $intentData, string $user_id, string $user_api_key): string
    {
        return $this->completeAuthTransaction($intentData, $user_id, $user_api_key);
    }

    /**
     * @param $intentData
     * @param $user_id
     * @param $user_api_key
     *
     * @return bool|string|null
     */
    private function tokenCCDelete($intentData, $user_id, $user_api_key): bool|string|null
    {
        $developer_id = $this->developerId;
        $url          = $this->fortisApi . "/v1/tokens/" . $intentData["tokenId"];

        unset($intentData['tokenId']);

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "DELETE",
                CURLOPT_POSTFIELDS     => json_encode($intentData),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $user_id",
                    "user-api-key: $user_api_key",
                    "developer-id: $developer_id",
                ],
            ]
        );

        $cnt           = 0;
        $intentCreated = false;
        $curlError     = null;
        $response      = null;
        while (!$intentCreated && $cnt < 5) {
            $response     = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($responseCode !== 200) {
                $curlError = curl_error($curl);
                $cnt++;
            }
            $intentCreated = true;
        }

        if (!$intentCreated) {
            // Do something with this error
            return $curlError;
        }

        return $response;
    }

    /**
     * Do Complete Auth Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return string
     */
    public function doTokenCCDelete(array $intentData, string $user_id, string $user_api_key): string
    {
        return $this->tokenCCDelete($intentData, $user_id, $user_api_key);
    }

    /**
     * Create a new postback for given location and service combination
     *
     * @return string|null
     * @throws \Exception
     */
    public function createTransactionWebhook(): string|null
    {
        $userId               = $this->config->userId();
        $userApiKey           = $this->config->userApiKey();
        $developerId          = $this->developerId;
        $locationId           = $this->config->achLocationId();
        $achEnabled           = $this->config->achIsActive();
        $productTransactionId = $this->config->achProductId();

        if (!$achEnabled) {
            return null;
        }

        $url  = $this->fortisApi . '/v1/webhooks/transaction';
        $curl = curl_init($url);

        $intentData = [
            'is_active'              => true,
            'location_id'            => $locationId,
            'on_create'              => 0,
            'on_update'              => 1,
            'on_delete'              => 1,
            'product_transaction_id' => $productTransactionId,
            'number_of_attempts'     => 1,
            'url'                    => $this->webhookUrl,
        ];

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => json_encode($intentData),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $userId",
                    "user-api-key: $userApiKey",
                    "developer-id: $developerId",
                ],
            ]
        );

        $response = curl_exec($curl);
        if (!curl_error($curl)) {
            $response = json_decode($response);
            if ($response->type === 'Error') {
                $errorStr = '';
                foreach ($response->meta->errors as $key => $error) {
                    $errorStr .= "$error[0]\n";
                }
                throw new \Exception($errorStr);
            }
            $webhookId = $response->data->id;

            return $webhookId;
        }

        return null;
    }

    /**
     * @param string $achWebhookId
     *
     * @return void
     * @throws \Exception
     */
    public function deleteTransactionWebhook(string $achWebhookId): void
    {
        $userId      = $this->config->userId();
        $userApiKey  = $this->config->userApiKey();
        $developerId = $this->developerId;

        $url  = $this->fortisApi . "/v1/webhooks/$achWebhookId";
        $curl = curl_init($url);

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "DELETE",
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $userId",
                    "user-api-key: $userApiKey",
                    "developer-id: $developerId",
                ],
            ]
        );

        $response = curl_exec($curl);
        if (!curl_error($curl)) {
            $response = json_decode($response);
            if ($response && $response->type === 'Error') {
                if ($response->statusCode === 404) {
                    return;
                }

                throw new \Exception($response->title);
            }
        }
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Directory\Model\CountryFactory $countryFactory
     *
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Exception
     */
    public function createVisaLevel3Entry(
        Order $order,
        StoreManagerInterface $storeManager,
        CountryFactory $countryFactory,
        string $transactionId
    ): void {
        $userId      = $this->config->userId();
        $userApiKey  = $this->config->userApiKey();
        $developerId = $this->developerId;

        $customerAddress = $order->getShippingAddress();
        if (!$customerAddress) {
            $customerAddress = $order->getBillingAddress();
        }
        $countryId                = $customerAddress->getCountryId();
        $country                  = $countryFactory->create()->loadByCode($countryId);
        $destination_country_code = '840';
        if ($country->getId()) {
            $destination_country_code = $country->getData('iso_numeric');
        }
        $destination_country_code = $destination_country_code ?? '840';

        $orderDate = $order->getCreatedAt();
        $orderDate = substr($orderDate, 2, 8);
        $orderDate = str_replace('-', '', $orderDate);

        $url        = $this->fortisApi . "/v1/transactions/$transactionId/level3/visa";
        $data       = [];
        $level3Data = [
            'destination_country_code' => $destination_country_code,
            'freight_amount'           => $order->getShippingAmount(),
            'shipfrom_zip_code'        => $storeManager->getStore()->getCode(),
            'shipto_zip_code'          => $customerAddress->getPostcode(),
            'tax_amount'               => $order->getTaxAmount(),
            'order_date'               => $orderDate,
        ];

        $lineItems = [];
        $items     = $order->getAllItems();
        foreach ($items as $item) {
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
        $data['level3_data']      = $level3Data;

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => json_encode($data),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $userId",
                    "user-api-key: $userApiKey",
                    "developer-id: $developerId",
                ],
            ]
        );

        $response = curl_exec($curl);
        if (($error = curl_error($curl)) !== '') {
            throw new \Exception('Curl error: ' . $error);
        }
        $response = json_decode($response);
        if ($response->type === 'Error') {
            throw new \Exception('Level 3 creation error: ' . $response->detail);
        }
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Directory\Model\CountryFactory $countryFactory
     *
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function createMcLevel3Entry(
        Order $order,
        StoreManagerInterface $storeManager,
        CountryFactory $countryFactory,
        string $transactionId
    ): void {
        $userId      = $this->config->userId();
        $userApiKey  = $this->config->userApiKey();
        $developerId = $this->developerId;

        $customerAddress = $order->getShippingAddress();
        if (!$customerAddress) {
            $customerAddress = $order->getBillingAddress();
        }
        $countryId                = $customerAddress->getCountryId();
        $country                  = $countryFactory->create()->loadByCode($countryId);
        $destination_country_code = '840';
        if ($country->getId()) {
            $destination_country_code = $country->getData('iso_numeric');
        }
        $destination_country_code = $destination_country_code ?? '840';

        $url        = $this->fortisApi . "/v1/transactions/$transactionId/level3/master-card";
        $data       = [];
        $level3Data = [
            'destination_country_code' => $destination_country_code,
            'freight_amount'           => $order->getShippingAmount(),
            'shipfrom_zip_code'        => $storeManager->getStore()->getCode(),
            'shipto_zip_code'          => $customerAddress->getPostcode(),
            'tax_amount'               => $order->getTaxAmount(),
        ];

        $lineItems = [];
        $items     = $order->getAllItems();
        foreach ($items as $item) {
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
        $data['level3_data']      = $level3Data;

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => json_encode($data),
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "user-id: $userId",
                    "user-api-key: $userApiKey",
                    "developer-id: $developerId",
                ],
            ]
        );

        $response = curl_exec($curl);
        if (($error = curl_error($curl)) !== '') {
            throw new \Exception('Curl error: ' . $error);
        }
        $response = json_decode($response);
        if ($response->type === 'Error') {
            throw new \Exception('Level 3 creation error: ' . $response->detail);
        }
    }
}
