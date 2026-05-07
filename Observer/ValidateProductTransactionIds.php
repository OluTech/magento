<?php

namespace Fortispay\Fortis\Observer;

use Fortispay\Fortis\Model\Config;
use Fortispay\Fortis\Model\FortisApi;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Observer to validate Product Transaction IDs against Fortis API
 */
class ValidateProductTransactionIds implements ObserverInterface
{
    /**
     * @var FortisApi
     */
    private $fortisApi;

    /**
     * @var Config
     */
    private $fortisConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * Currency field mappings
     */
    private const CURRENCY_FIELD_MAP = [
        'product_transaction_id'           => 'product_currency_primary',
        'product_transaction_id_secondary' => 'product_currency_secondary'
    ];

    /**
     * @param FortisApi $fortisApi
     * @param Config $fortisConfig
     * @param LoggerInterface $logger
     * @param RequestInterface $request
     */
    public function __construct(
        FortisApi $fortisApi,
        Config $fortisConfig,
        LoggerInterface $logger,
        RequestInterface $request
    ) {
        $this->fortisApi    = $fortisApi;
        $this->fortisConfig = $fortisConfig;
        $this->logger       = $logger;
        $this->request      = $request;
    }

    /**
     * Validate Product Transaction IDs when payment configuration is saved
     *
     * @param Observer $observer
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        $configData   = $this->request->getParam('groups', []);
        $fortisConfig = $configData['fortis']['fields'] ?? [];

        if (empty($fortisConfig)) {
            return;
        }

        foreach (self::CURRENCY_FIELD_MAP as $productIdField => $currencyField) {
            $this->validateProductIdCurrencyPair($fortisConfig, $productIdField, $currencyField);
        }
    }

    /**
     * Check if any Fortis-related configuration paths changed
     *
     * @param array $changedPaths
     * @return bool
     */
    private function isFortisConfigChanged(array $changedPaths): bool
    {
        foreach ($changedPaths as $path) {
            if (strpos($path, 'payment/fortis/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate a Product Transaction ID and currency pair
     *
     * @param array $fortisConfig
     * @param string $productIdField
     * @param string $currencyField
     * @throws LocalizedException
     */
    private function validateProductIdCurrencyPair(
        array $fortisConfig,
        string $productIdField,
        string $currencyField
    ): void {
        $productId = $fortisConfig[$productIdField]['value'] ?? null;
        $currency  = $fortisConfig[$currencyField]['value'] ?? null;

        if (empty($currency)) {
            return;
        }

        if ($productId !== null && preg_match('/^\*+$/', $productId)) {
            $productId = null;
        }

        if (empty($productId)) {
            try {
                if ($productIdField === 'product_transaction_id') {
                    $productId = $this->fortisConfig->ccProductId() ?: null;
                } elseif ($productIdField === 'product_transaction_id_secondary') {
                    $productId = $this->fortisConfig->getSecondaryProductId() ?: null;
                }
            } catch (\Exception $e) {
                $productId = null;
            }
        }

        if (empty($productId)) {
            return;
        }

        try {
            $credentials = $this->extractCredentials($fortisConfig);

            if (!$credentials['userId'] || !$credentials['userApiKey']) {
                return;
            }

            $isValid = $this->fortisApi->validateProductIdCurrency(
                $productId,
                $currency,
                $credentials['userId'],
                $credentials['userApiKey']
            );

            if (!$isValid) {
                throw new LocalizedException(
                    __(
                        'Product Transaction ID %1 is not valid for currency %2. Please verify the Product ID supports the selected currency in your Fortis dashboard.',
                        $productId,
                        $currency
                    )
                );
            }
        } catch (LocalizedException $e) {
            throw new LocalizedException(
                __('Configuration Save Failed - %1', $e->getMessage())
            );
        } catch (\Exception $e) {
            $environment = $this->getEnvironment($fortisConfig);
            if ($environment === 'sandbox') {
                throw new LocalizedException(
                    __('Unable to validate Product Transaction ID against Fortis API (Sandbox): %1', $e->getMessage())
                );
            }
        }
    }

    /**
     * Extract user credentials from configuration data
     *
     * @param array $fortisConfig
     * @return array
     */
    private function extractCredentials(array $fortisConfig): array
    {
        $userId     = $fortisConfig['user_id']['value'] ?? null;
        $userApiKey = $fortisConfig['user_api_key']['value'] ?? null;

        if ($userId !== null && preg_match('/^\*+$/', $userId)) {
            $userId = null;
        }
        if ($userApiKey !== null && preg_match('/^\*+$/', $userApiKey)) {
            $userApiKey = null;
        }

        if (!$userId || !$userApiKey) {
            try {
                $userId     = $userId ?: $this->fortisConfig->userId();
                $userApiKey = $userApiKey ?: $this->fortisConfig->userApiKey();
            } catch (\Exception $e) {
                $userId     = $userId ?: null;
                $userApiKey = $userApiKey ?: null;
            }
        }

        return [
            'userId'     => $userId,
            'userApiKey' => $userApiKey
        ];
    }

    /**
     * Get environment setting from configuration
     *
     * @param array $fortisConfig
     * @return string
     */
    private function getEnvironment(array $fortisConfig): string
    {
        $environment = $fortisConfig['fortis_environment']['value'] ?? null;

        if (!$environment) {
            try {
                $environment = $this->fortisConfig->environment();
            } catch (\Exception $e) {
                $environment = 'production';
            }
        }

        return $environment ?: 'production';
    }
}
