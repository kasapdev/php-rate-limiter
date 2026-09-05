# php-rate-limiter

A zero-dependency PHP rate limiter. Pluggable storage (in-process array, or one-JSON-file-per-key
on disk), a **token bucket** limiter (smooth, continuous refill — good for bursty traffic with an
average-rate cap) and a **sliding window** limiter (a hard cap on requests within a trailing time
window).

## Installation

Once published to Packagist:

```bash
composer require kasapdev/php-rate-limiter
```

Or just require the files directly:

```php
require_once 'src/StorageInterface.php';
require_once 'src/ArrayStorage.php';
require_once 'src/FileStorage.php';
require_once 'src/TokenBucketLimiter.php';
require_once 'src/SlidingWindowLimiter.php';
```

## Usage

### Token bucket

```php
use Kasapdev\RateLimiter\ArrayStorage;
use Kasapdev\RateLimiter\TokenBucketLimiter;

$storage = new ArrayStorage(); // or new FileStorage(__DIR__ . '/storage/rate-limits')
$limiter = new TokenBucketLimiter($storage, capacity: 10, refillRatePerSecond: 2.0);

if ($limiter->attempt('user:42')) {
    // allowed - one token consumed
} else {
    // rate limited
}

// A single call can also cost more than one token:
$limiter->attempt('user:42', cost: 5);

// Inspect the current balance without consuming anything:
$tokensLeft = $limiter->remaining('user:42');
```

The bucket starts full (`capacity` tokens) the first time a key is seen, and refills continuously
based on wall-clock time elapsed since the last recorded state — there's no background process
or cron job; refill is computed lazily on each `attempt()`/`remaining()` call.

### Sliding window

```php
use Kasapdev\RateLimiter\ArrayStorage;
use Kasapdev\RateLimiter\SlidingWindowLimiter;

$storage = new ArrayStorage();
$limiter = new SlidingWindowLimiter($storage, maxRequests: 100, windowSeconds: 60);

if ($limiter->attempt('ip:203.0.113.7')) {
    // allowed
} else {
    // more than 100 requests already recorded in the trailing 60 seconds
}

$limiter->remaining('ip:203.0.113.7'); // requests still available in the current window
```

### Storage backends

```php
use Kasapdev\RateLimiter\ArrayStorage; // process-local, gone when the process exits
use Kasapdev\RateLimiter\FileStorage;  // persists to disk, one JSON file per key

$storage = new FileStorage('/tmp/rate-limits'); // directory is created if missing
```

Implement `Kasapdev\RateLimiter\StorageInterface` yourself to back a limiter with Redis,
Memcached, a database table, etc. — it's two methods:

```php
interface StorageInterface
{
    public function get(string $key): ?array;
    public function set(string $key, array $value, int $ttlSeconds): void;
}
```

## API

### `TokenBucketLimiter`

- `__construct(StorageInterface $storage, int $capacity, float $refillRatePerSecond)`
- `attempt(string $key, int $cost = 1): bool`
- `remaining(string $key): int`

### `SlidingWindowLimiter`

- `__construct(StorageInterface $storage, int $maxRequests, int $windowSeconds)`
- `attempt(string $key): bool`
- `remaining(string $key): int`

### `ArrayStorage` / `FileStorage`

Both implement `StorageInterface`. TTL is tracked as an absolute expiry timestamp; an entry past
its expiry is treated as if it never existed and is cleaned up as soon as it's next accessed
(`ArrayStorage` drops it from the in-memory array, `FileStorage` deletes the backing file).

## Testing

```bash
php tests/run.php
```

Refill-over-time behavior for the token bucket, and window-pruning for the sliding window, are
tested deterministically by reading the limiter's persisted state back out through
`StorageInterface::get()`, rewriting its timestamp(s) into the past, and writing it back with
`StorageInterface::set()` — simulating elapsed wall-clock time without any `sleep()` calls.

## License

MIT
