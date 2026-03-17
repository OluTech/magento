<?php

namespace Fortispay\Fortis\Service;

use Fortispay\Fortis\Model\FortisApi;
use Fortispay\Fortis\Model\Config;

class TransactionVerifier
{
    private FortisApi $fortisApi;
    private Config $config;

    public function __construct(
        FortisApi $fortisApi,
        Config $config
    ) {
        $this->fortisApi = $fortisApi;
        $this->config    = $config;
    }

    /**
     * Verify a transaction by fetching it from Fortis API
     *
     * @param string $fortisTransactionId The Fortis transaction ID to fetch and verify
     * @param string|int $expectedOrderId The expected order/transaction ID
     * @param int $checkoutTotal The checkout total in cents
     * @param array|null $surchargeInfo Optional surcharge information from client
     *
     * @return array ['verified' => bool, 'errors' => array, 'transaction' => array|null]
     */
    public function verifyTransactionById(
        string $fortisTransactionId,
        $expectedOrderId,
        int $checkoutTotal,
        ?array $surchargeInfo = null
    ): array {
        // Fetch transaction from Fortis API
        $transactionObj = $this->fortisApi->getTransaction(
            $fortisTransactionId,
            $this->config->userId(),
            $this->config->userApiKey()
        );

        $transaction = json_decode(json_encode($transactionObj), true);

        if (!is_array($transaction) || !isset($transaction['data'])) {
            return [
                'verified'    => false,
                'errors'      => [
                    [
                        'type'           => 'fetch_failed',
                        'field'          => 'transaction',
                        'message'        => 'Failed to fetch transaction from Fortis API',
                        'transaction_id' => $fortisTransactionId,
                    ],
                ],
                'transaction' => null,
            ];
        }

        // Perform verification
        $expectedTotal = $checkoutTotal;

        // Try to get surcharge from API response first
        if (isset($transaction['data']['surcharge_amount']) && $transaction['data']['surcharge_amount'] > 0) {
            $surchargeAmount = (int)$transaction['data']['surcharge_amount'];
            $expectedTotal   += $surchargeAmount;
        } elseif ($surchargeInfo && isset($surchargeInfo['surchargeAmount']) && $surchargeInfo['surchargeAmount'] > 0) {
            // Fall back to surcharge info passed from client/transaction record
            $surchargeAmount = (int)$surchargeInfo['surchargeAmount'];
            $expectedTotal   += $surchargeAmount;
        }

        $verified                 = true;
        $errors                   = [];
        $transactionAmountFromApi = $transaction['data']['transaction_amount'] ?? 0;

        // API returns transaction_amount in cents as an integer
        $transactionAmount = (int)$transactionAmountFromApi;

        $actualDescription = $transaction['data']['description'] ?? null;

        // Verify transaction description matches transaction ID
        if (((int)$actualDescription !== (int)$expectedOrderId)) {
            $verified = false;
            $errors[] = [
                'type'           => 'description_mismatch',
                'field'          => 'description',
                'expected'       => (string)$expectedOrderId,
                'actual'         => (string)$actualDescription,
                'transaction_id' => (string)$expectedOrderId
            ];
        }

        // Verify transaction amount matches expected total
        if ($transactionAmount !== $expectedTotal) {
            $verified = false;
            $errors[] = [
                'type'           => 'amount_mismatch',
                'field'          => 'transaction_amount',
                'expected'       => $expectedTotal,
                'actual'         => $transactionAmount,
                'difference'     => $transactionAmount - $expectedTotal,
                'transaction_id' => (string)$expectedOrderId
            ];
        }

        return [
            'verified'    => $verified,
            'errors'      => $errors,
            'transaction' => $transaction
        ];
    }

    /**
     * Verify a transaction by ID and return only the boolean result
     *
     * @param string $fortisTransactionId The Fortis transaction ID to fetch and verify
     * @param string|int $expectedOrderId The expected order/transaction ID
     * @param int $checkoutTotal The checkout total in cents
     * @param array|null $surchargeInfo Optional surcharge information from client
     *
     * @return bool
     */
    public function isTransactionVerifiedById(
        string $fortisTransactionId,
        $expectedOrderId,
        int $checkoutTotal,
        ?array $surchargeInfo = null
    ): bool {
        $result = $this->verifyTransactionById($fortisTransactionId, $expectedOrderId, $checkoutTotal, $surchargeInfo);
        return $result['verified'];
    }
}
