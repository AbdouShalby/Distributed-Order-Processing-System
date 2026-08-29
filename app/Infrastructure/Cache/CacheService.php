<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Application-level cache service.
 *
 * Wraps Laravel's cache with structured keys, tagging,
 * and consistent TTL management for the DOPS system.
 */
class CacheService
{
    private const PREFIX = 'dops:';

    // ─── TTL Constants (seconds) ──────────────────────

    public const TTL_PRODUCTS = 300;       // 5 minutes

    public const TTL_PRODUCT_LIST = 600;   // 10 minutes

    public const TTL_ORDER = 60;           // 1 minute

    public const TTL_HEALTH = 10;          // 10 seconds

    // ─── Generic Operations ───────────────────────────

    /**
     * Get a cached value, or compute and store it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return Cache::remember(self::PREFIX.$key, $ttl, $callback);
    }

    /**
     * Get a cached value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get(self::PREFIX.$key, $default);
    }

    /**
     * Store a value in cache.
     */
    public function put(string $key, mixed $value, int $ttl): void
    {
        Cache::put(self::PREFIX.$key, $value, $ttl);
    }

    /**
     * Remove a cached value.
     */
    public function forget(string $key): void
    {
        Cache::forget(self::PREFIX.$key);

        Log::debug('Cache invalidated', [
            'key' => self::PREFIX.$key,
        ]);
    }

    /**
     * Flush ALL cache entries with the DOPS prefix.
     * Use with caution — typically only in tests or admin ops.
     */
    public function flush(): void
    {
        Cache::flush();

        Log::info('Cache flushed entirely');
    }

    // ─── Domain-Specific Keys ─────────────────────────

    /**
     * Cache key for the product list.
     */
    public static function productListKey(): string
    {
        return 'products:all';
    }

    /**
     * Cache key for a single product.
     */
    public static function productKey(int $productId): string
    {
        return "products:{$productId}";
    }

    /**
     * Cache key for an order.
     */
    public static function orderKey(int $orderId): string
    {
        return "orders:{$orderId}";
    }

    /**
     * Cache key for a user's order list.
     */
    public static function userOrdersKey(int $userId, ?string $status, int $page): string
    {
        $suffix = $status ? ":{$status}" : ':all';

        return "users:{$userId}:orders{$suffix}:page:{$page}";
    }
}
