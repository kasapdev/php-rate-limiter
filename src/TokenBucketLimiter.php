<?php

declare(strict_types=1);

namespace Kasapdev\RateLimiter;

/**
 * Classic token bucket rate limiter. A bucket holds up to $capacity tokens
 * and refills continuously at $refillRatePerSecond tokens/second, based on
 * elapsed wall-clock time since the last recorded state. Each attempt()
 * costs $cost tokens; it succeeds only if enough tokens are available.
 */
final class TokenBucketLimiter
{
    /** How long an idle bucket's state is retained in storage before it may be evicted. */
    private const STATE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly int $capacity,
        private readonly float $refillRatePerSecond
    ) {
    }

    /**
     * Attempt to consume $cost tokens from the bucket for $key.
     */
    public function attempt(string $key, int $cost = 1): bool
    {
        $now = microtime(true);
        $tokens = $this->refillTokens($key, $now);

        if ($tokens < $cost) {
            $this->saveState($key, $tokens, $now);

            return false;
        }

        $this->saveState($key, $tokens - $cost, $now);

        return true;
    }

    /**
     * Tokens currently available for $key (after applying refill), without
     * consuming any or persisting the recalculated state.
     */
    public function remaining(string $key): int
    {
        return (int) floor($this->refillTokens($key, microtime(true)));
    }

    /**
     * Compute the current token count for $key at time $now, applying
     * refill accrued since the last recorded state (without persisting).
     */
    private function refillTokens(string $key, float $now): float
    {
        $state = $this->storage->get($this->storageKey($key));

        if ($state === null) {
            return (float) $this->capacity;
        }

        $tokens = (float) ($state['tokens'] ?? $this->capacity);
        $lastRefill = (float) ($state['lastRefill'] ?? $now);

        $elapsed = max(0.0, $now - $lastRefill);
        $refilled = $elapsed * $this->refillRatePerSecond;

        return min((float) $this->capacity, $tokens + $refilled);
    }

    private function saveState(string $key, float $tokens, float $now): void
    {
        $this->storage->set(
            $this->storageKey($key),
            ['tokens' => $tokens, 'lastRefill' => $now],
            self::STATE_TTL_SECONDS
        );
    }

    private function storageKey(string $key): string
    {
        return 'token_bucket:' . $key;
    }
}
