<?php

declare(strict_types=1);

namespace Kasapdev\RateLimiter;

/**
 * Sliding window rate limiter. Keeps a per-key log of request timestamps;
 * an attempt() succeeds only if fewer than $maxRequests timestamps remain
 * within the trailing $windowSeconds window. Entries older than the window
 * are pruned on every access.
 */
final class SlidingWindowLimiter
{
    /** Extra retention so a key's log isn't evicted from storage mid-window. */
    private const STATE_TTL_BUFFER_SECONDS = 5;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly int $maxRequests,
        private readonly int $windowSeconds
    ) {
    }

    public function attempt(string $key): bool
    {
        $storageKey = $this->storageKey($key);
        $now = microtime(true);
        $windowStart = $now - $this->windowSeconds;

        $timestamps = $this->prune($this->loadTimestamps($storageKey), $windowStart);

        if (count($timestamps) >= $this->maxRequests) {
            $this->save($storageKey, $timestamps);

            return false;
        }

        $timestamps[] = $now;
        $this->save($storageKey, $timestamps);

        return true;
    }

    /**
     * Requests still available in the current window for $key, without
     * recording a new attempt.
     */
    public function remaining(string $key): int
    {
        $now = microtime(true);
        $windowStart = $now - $this->windowSeconds;
        $timestamps = $this->prune($this->loadTimestamps($this->storageKey($key)), $windowStart);

        return max(0, $this->maxRequests - count($timestamps));
    }

    /** @return float[] */
    private function loadTimestamps(string $storageKey): array
    {
        $state = $this->storage->get($storageKey);
        $timestamps = $state['timestamps'] ?? [];

        return array_map(static fn (mixed $t): float => (float) $t, is_array($timestamps) ? $timestamps : []);
    }

    /**
     * @param float[] $timestamps
     * @return float[]
     */
    private function prune(array $timestamps, float $windowStart): array
    {
        return array_values(array_filter($timestamps, static fn (float $t): bool => $t > $windowStart));
    }

    /** @param float[] $timestamps */
    private function save(string $storageKey, array $timestamps): void
    {
        $this->storage->set($storageKey, ['timestamps' => $timestamps], $this->windowSeconds + self::STATE_TTL_BUFFER_SECONDS);
    }

    private function storageKey(string $key): string
    {
        return 'sliding_window:' . $key;
    }
}
