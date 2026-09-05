<?php

declare(strict_types=1);

namespace Kasapdev\RateLimiter;

/**
 * A minimal key-value store with per-entry TTL, used by the rate limiters
 * to persist their bucket/window state between calls.
 */
interface StorageInterface
{
    /**
     * Fetch the value stored under $key, or null if it doesn't exist or has expired.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * Store $value under $key, expiring it after $ttlSeconds.
     *
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value, int $ttlSeconds): void;
}
