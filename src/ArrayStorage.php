<?php

declare(strict_types=1);

namespace Kasapdev\RateLimiter;

/**
 * In-process, process-local storage. State does not survive past the end of
 * the current PHP process/request and is not shared across processes.
 * TTL is tracked as an absolute expiry timestamp (microtime(true) + ttl);
 * expired entries are treated as missing and removed lazily on access.
 */
final class ArrayStorage implements StorageInterface
{
    /** @var array<string, array{expiresAt: float, value: array<string, mixed>}> */
    private array $entries = [];

    public function get(string $key): ?array
    {
        if (!isset($this->entries[$key])) {
            return null;
        }

        $entry = $this->entries[$key];

        if ($entry['expiresAt'] < microtime(true)) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry['value'];
    }

    public function set(string $key, array $value, int $ttlSeconds): void
    {
        $this->entries[$key] = [
            'expiresAt' => microtime(true) + $ttlSeconds,
            'value' => $value,
        ];
    }
}
