<?php

namespace Fortispay\Fortis\Service;

use Fortispay\Fortis\Model\Config;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Fixed-window per-IP rate limiter backed by the Magento cache pool.
 *
 * Uses the cache pool (rather than PHP session) so limits hold even against
 * scripted callers that never persist a session cookie between requests.
 */
class RateLimiter
{
    private const CACHE_ID_PREFIX = 'fortis_rate_limit_';

    private const CACHE_TAG = 'FORTIS_RATE_LIMIT';

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * @var RemoteAddress
     */
    private RemoteAddress $remoteAddress;

    /**
     * @var SerializerInterface
     */
    private SerializerInterface $serializer;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param CacheInterface $cache
     * @param RemoteAddress $remoteAddress
     * @param SerializerInterface $serializer
     * @param Config $config
     */
    public function __construct(
        CacheInterface $cache,
        RemoteAddress $remoteAddress,
        SerializerInterface $serializer,
        Config $config
    ) {
        $this->cache         = $cache;
        $this->remoteAddress = $remoteAddress;
        $this->serializer    = $serializer;
        $this->config        = $config;
    }

    /**
     * Record an attempt for the given action/client and report whether it is within the allowed rate.
     *
     * Always allowed (and no counter recorded) when the merchant has turned rate limiting off for this
     * store, e.g. to accommodate their own high-volume headless integration.
     *
     * @param string $action Unique key identifying the rate-limited action.
     * @param int $maxAttempts Maximum attempts allowed within the window.
     * @param int $windowSeconds Length of the window in seconds.
     * @return bool True if the caller is within the limit (attempt recorded), false if the limit was exceeded.
     */
    public function isAllowed(string $action, int $maxAttempts, int $windowSeconds): bool
    {
        if (!$this->config->isRateLimitingEnabled()) {
            return true;
        }

        $ip       = $this->remoteAddress->getRemoteAddress() ?: 'unknown';
        $cacheKey = self::CACHE_ID_PREFIX . hash('sha256', $action . '|' . $ip);
        $now      = time();

        $decoded = $this->load($cacheKey);

        if (!is_array($decoded) || !isset($decoded['count'], $decoded['start'])
            || ($now - (int)$decoded['start']) >= $windowSeconds
        ) {
            $this->store($cacheKey, ['count' => 1, 'start' => $now], $windowSeconds);
            return true;
        }

        if ((int)$decoded['count'] >= $maxAttempts) {
            return false;
        }

        $remainingTtl = max(1, $windowSeconds - ($now - (int)$decoded['start']));
        $this->store(
            $cacheKey,
            ['count' => (int)$decoded['count'] + 1, 'start' => (int)$decoded['start']],
            $remainingTtl
        );

        return true;
    }

    /**
     * Load and decode the counter stored for a cache key, if any.
     *
     * @param string $cacheKey
     * @return array|null
     */
    private function load(string $cacheKey): ?array
    {
        $raw = $this->cache->load($cacheKey);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Encode and store a counter under a cache key.
     *
     * @param string $cacheKey
     * @param array $data
     * @param int $ttl
     * @return void
     */
    private function store(string $cacheKey, array $data, int $ttl): void
    {
        $this->cache->save($this->serializer->serialize($data), $cacheKey, [self::CACHE_TAG], $ttl);
    }
}
