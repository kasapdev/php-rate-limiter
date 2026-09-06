<?php

declare(strict_types=1);

$__failures = 0;
function check(string $label, bool $condition): void
{
    global $__failures;
    echo ($condition ? "[PASS] " : "[FAIL] ") . $label . "\n";
    if (!$condition) {
        $__failures++;
    }
}

require_once __DIR__ . '/../src/StorageInterface.php';
require_once __DIR__ . '/../src/ArrayStorage.php';
require_once __DIR__ . '/../src/FileStorage.php';
require_once __DIR__ . '/../src/TokenBucketLimiter.php';
require_once __DIR__ . '/../src/SlidingWindowLimiter.php';

use Kasapdev\RateLimiter\ArrayStorage;
use Kasapdev\RateLimiter\FileStorage;
use Kasapdev\RateLimiter\SlidingWindowLimiter;
use Kasapdev\RateLimiter\StorageInterface;
use Kasapdev\RateLimiter\TokenBucketLimiter;

// --- ArrayStorage ------------------------------------------------------------------------

$storage = new ArrayStorage();
check('ArrayStorage returns null for a missing key', $storage->get('missing') === null);

$storage->set('a', ['x' => 1], 60);
check('ArrayStorage returns what was stored', $storage->get('a') === ['x' => 1]);

$storage->set('expiring', ['y' => 2], -1); // already expired (ttl in the past)
check('ArrayStorage treats an expired entry as missing', $storage->get('expiring') === null);

// --- FileStorage ---------------------------------------------------------------------------

$tmpDir = sys_get_temp_dir() . '/kasapdev-rate-limiter-test-' . uniqid('', true);
$fileStorage = new FileStorage($tmpDir);

check('FileStorage creates its directory', is_dir($tmpDir));
check('FileStorage returns null for a missing key', $fileStorage->get('missing') === null);

$fileStorage->set('a', ['x' => 1], 60);
check('FileStorage returns what was stored', $fileStorage->get('a') === ['x' => 1]);

$filesBefore = glob($tmpDir . '/*.json');
check('FileStorage writes exactly one file per key', is_array($filesBefore) && count($filesBefore) === 1);

$fileStorage->set('expiring', ['y' => 2], -1);
check('FileStorage treats an expired entry as missing', $fileStorage->get('expiring') === null);

$filesAfterExpiry = glob($tmpDir . '/*.json');
check('FileStorage cleans up the expired file on access', is_array($filesAfterExpiry) && count($filesAfterExpiry) === 1);

// cleanup
foreach (glob($tmpDir . '/*.json') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmpDir);

// --- TokenBucketLimiter: basic capacity + exhaustion ------------------------------------------

$storage = new ArrayStorage();
$limiter = new TokenBucketLimiter($storage, capacity: 3, refillRatePerSecond: 1.0);

check('first attempt within capacity succeeds', $limiter->attempt('user1') === true);
check('second attempt within capacity succeeds', $limiter->attempt('user1') === true);
check('third attempt within capacity succeeds', $limiter->attempt('user1') === true);
check('fourth attempt exceeds capacity and fails', $limiter->attempt('user1') === false);
check('remaining() reports 0 tokens after exhausting the bucket', $limiter->remaining('user1') === 0);

// A different key has its own independent bucket.
check('a different key has a fresh, independent bucket', $limiter->attempt('user2') === true);

// --- TokenBucketLimiter: cost > 1 -------------------------------------------------------------

$storage = new ArrayStorage();
$limiter = new TokenBucketLimiter($storage, capacity: 5, refillRatePerSecond: 0.0);
check('attempt with cost=3 succeeds within capacity', $limiter->attempt('bulk', 3) === true);
check('remaining after cost=3 attempt is 2', $limiter->remaining('bulk') === 2);
check('attempt with cost=3 fails when only 2 tokens remain', $limiter->attempt('bulk', 3) === false);
check('attempt with cost=2 succeeds with exactly 2 tokens remaining', $limiter->attempt('bulk', 2) === true);

// --- TokenBucketLimiter: refill-over-time behavior, simulated via direct storage manipulation ---

$storage = new ArrayStorage();
$limiter = new TokenBucketLimiter($storage, capacity: 10, refillRatePerSecond: 2.0); // 2 tokens/sec

// Drain the bucket completely.
check('drain token bucket to zero', $limiter->attempt('drip', 10) === true);
check('bucket is empty immediately after draining', $limiter->attempt('drip', 1) === false);

// Simulate 3 elapsed seconds by rewriting the stored lastRefill timestamp
// further into the past -- no sleep() needed.
$rawKey = 'token_bucket:drip';
$state = $storage->get($rawKey);
check('internal bucket state is readable via the storage interface', is_array($state));
$state['lastRefill'] = $state['lastRefill'] - 3.0; // pretend 3 seconds have passed
$storage->set($rawKey, $state, 86400);

// At 2 tokens/sec for 3 simulated seconds, 6 tokens should now be available.
check('remaining() reflects tokens refilled over the simulated elapsed time', $limiter->remaining('drip') === 6);
check('attempt() succeeds once enough time has passed to refill', $limiter->attempt('drip', 5) === true);
check('remaining tokens after consuming 5 of the 6 refilled tokens is 1', $limiter->remaining('drip') === 1);

// Refill never exceeds capacity even after a very long simulated gap.
$state = $storage->get($rawKey);
$state['lastRefill'] = $state['lastRefill'] - 1000.0;
$storage->set($rawKey, $state, 86400);
check('refill is capped at the bucket capacity', $limiter->remaining('drip') === 10);

// --- TokenBucketLimiter: fresh key starts full ------------------------------------------------

$storage = new ArrayStorage();
$limiter = new TokenBucketLimiter($storage, capacity: 4, refillRatePerSecond: 1.0);
check('remaining() for a never-seen key equals the full capacity', $limiter->remaining('brand-new') === 4);

// --- SlidingWindowLimiter: basic window enforcement ---------------------------------------------

$storage = new ArrayStorage();
$limiter = new SlidingWindowLimiter($storage, maxRequests: 3, windowSeconds: 60);

check('sliding window attempt 1 succeeds', $limiter->attempt('ip1') === true);
check('sliding window attempt 2 succeeds', $limiter->attempt('ip1') === true);
check('sliding window attempt 3 succeeds', $limiter->attempt('ip1') === true);
check('sliding window attempt 4 fails (limit reached)', $limiter->attempt('ip1') === false);
check('remaining() reports 0 once the window limit is hit', $limiter->remaining('ip1') === 0);

check('a different key has its own independent window', $limiter->attempt('ip2') === true);

// --- SlidingWindowLimiter: pruning of entries outside the window (simulated via storage) --------

$storage = new ArrayStorage();
$limiter = new SlidingWindowLimiter($storage, maxRequests: 2, windowSeconds: 10);

check('window fill 1/2', $limiter->attempt('pruned') === true);
check('window fill 2/2', $limiter->attempt('pruned') === true);
check('window full, 3rd attempt rejected', $limiter->attempt('pruned') === false);

// Push both recorded timestamps outside the window by rewriting stored state directly.
$rawKey = 'sliding_window:pruned';
$state = $storage->get($rawKey);
check('sliding window internal state is readable via storage interface', is_array($state) && isset($state['timestamps']));
$state['timestamps'] = array_map(static fn (float $t): float => $t - 20.0, $state['timestamps']); // 20s in the past, window is 10s
$storage->set($rawKey, $state, 15);

check('remaining() is back to full after simulated timestamps age out of the window', $limiter->remaining('pruned') === 2);
check('attempt() succeeds again once old entries fall outside the window', $limiter->attempt('pruned') === true);

// --- SlidingWindowLimiter works with FileStorage too ---------------------------------------------

$tmpDir2 = sys_get_temp_dir() . '/kasapdev-rate-limiter-test-' . uniqid('', true);
$fileStorage = new FileStorage($tmpDir2);
$limiter = new SlidingWindowLimiter($fileStorage, maxRequests: 1, windowSeconds: 60);
check('sliding window over FileStorage: first attempt succeeds', $limiter->attempt('file-user') === true);
check('sliding window over FileStorage: second attempt fails', $limiter->attempt('file-user') === false);
foreach (glob($tmpDir2 . '/*.json') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmpDir2);

// --- TokenBucketLimiter works with FileStorage too -----------------------------------------------

$tmpDir3 = sys_get_temp_dir() . '/kasapdev-rate-limiter-test-' . uniqid('', true);
$fileStorage = new FileStorage($tmpDir3);
$limiter = new TokenBucketLimiter($fileStorage, capacity: 2, refillRatePerSecond: 1.0);
check('token bucket over FileStorage: first attempt succeeds', $limiter->attempt('file-bucket') === true);
check('token bucket over FileStorage: second attempt succeeds', $limiter->attempt('file-bucket') === true);
check('token bucket over FileStorage: third attempt fails', $limiter->attempt('file-bucket') === false);
foreach (glob($tmpDir3 . '/*.json') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmpDir3);

// --- FileStorage: corrupted (non-JSON) file content is treated as missing ----------------------

$tmpDir4 = sys_get_temp_dir() . '/kasapdev-rate-limiter-test-' . uniqid('', true);
$fileStorage = new FileStorage($tmpDir4);
$fileStorage->set('corrupt-me', ['x' => 1], 60);
$corruptPath = (glob($tmpDir4 . '/*.json') ?: [])[0] ?? null;
check('corrupted-file setup: exactly one file exists to corrupt', $corruptPath !== null);
if ($corruptPath !== null) {
    file_put_contents($corruptPath, 'this is not valid json{{{');
    check('FileStorage::get() returns null for a file with corrupted JSON content', $fileStorage->get('corrupt-me') === null);
    check('FileStorage deletes the corrupted file on access', !is_file($corruptPath));
}
@rmdir($tmpDir4);

// --- FileStorage: different keys never collide onto the same file ------------------------------

$tmpDir5 = sys_get_temp_dir() . '/kasapdev-rate-limiter-test-' . uniqid('', true);
$fileStorage = new FileStorage($tmpDir5);
$fileStorage->set('key-one', ['v' => 1], 60);
$fileStorage->set('key-two', ['v' => 2], 60);
check('FileStorage stores different keys as different files', count(glob($tmpDir5 . '/*.json') ?: []) === 2);
check('FileStorage keeps values for different keys independent (key-one)', $fileStorage->get('key-one') === ['v' => 1]);
check('FileStorage keeps values for different keys independent (key-two)', $fileStorage->get('key-two') === ['v' => 2]);
foreach (glob($tmpDir5 . '/*.json') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmpDir5);

// --- TokenBucketLimiter: cost=0 always succeeds and never consumes tokens ----------------------

$storage = new ArrayStorage();
$limiter = new TokenBucketLimiter($storage, capacity: 2, refillRatePerSecond: 0.0);
check('attempt() with cost=0 succeeds', $limiter->attempt('zero-cost', 0) === true);
check('attempt() with cost=0 does not consume any tokens', $limiter->remaining('zero-cost') === 2);

// --- TokenBucketLimiter: zero capacity always rejects ------------------------------------------

$storage = new ArrayStorage();
$limiter = new TokenBucketLimiter($storage, capacity: 0, refillRatePerSecond: 1.0);
check('a bucket with zero capacity rejects even a cost=1 attempt', $limiter->attempt('empty-bucket') === false);
check('remaining() on a zero-capacity bucket is 0', $limiter->remaining('empty-bucket') === 0);

// --- SlidingWindowLimiter: maxRequests=0 rejects every attempt ---------------------------------

$storage = new ArrayStorage();
$limiter = new SlidingWindowLimiter($storage, maxRequests: 0, windowSeconds: 60);
check('a window with maxRequests=0 rejects the very first attempt', $limiter->attempt('no-requests-allowed') === false);
check('remaining() on a maxRequests=0 window is 0, not negative', $limiter->remaining('no-requests-allowed') === 0);

echo $__failures === 0 ? "\nAll tests passed.\n" : "\n$__failures test(s) FAILED.\n";
exit($__failures === 0 ? 0 : 1);
