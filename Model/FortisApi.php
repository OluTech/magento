<?php

namespace Fortispay\Fortis\Model;

use Magento\Framework\Exception\LocalizedException;
use stdClass;

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

    /**
     * Construct
     *
     * @param string $environment
     */
    public function __construct(string $environment)
    {
        if ($environment === 'production') {
            $this->developerId = self::DEVELOPER_ID;
            $this->fortisApi   = self::FORTIS_API;
        } else {
            $this->developerId = self::DEVELOPER_ID_SANDBOX;
            $this->fortisApi   = self::FORTIS_API_SANDBOX;
        }
    }

    /**
     * Get Client Token
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return string
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
    public function getTokenBody(string $token)
    {
        $parts = explode('.', $token);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $body = base64_decode($parts[1]);
        // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage

        return $body;
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
    public function refundTransactionAmount(array $intentData, string $user_id, string $user_api_key)
    {
        return $this->refundTransaction($intentData, $user_id, $user_api_key);
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
    public function refundAuthAmount(array $intentData, string $user_id, string $user_api_key)
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

//        $response = json_decode($response);

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
    private function refundTransaction(array $intentData, string $user_id, string $user_api_key)
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
     * Void Auth Transaction
     *
     * @param array $intentData
     * @param string $user_id
     * @param string $user_api_key
     *
     * @return bool|string|null
     */
    private function voidAuthTransaction(array $intentData, string $user_id, string $user_api_key)
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
    private function authTransaction(array $intentData, string $user_id, string $user_api_key)
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
        $url          = $this->fortisApi . "/v1/transactions/". $intentData["transactionId"] . "/auth-complete";

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
}
