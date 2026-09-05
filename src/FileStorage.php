<?php

declare(strict_types=1);

namespace Kasapdev\RateLimiter;

use RuntimeException;

/**
 * File-backed storage: one JSON file per key, in a given directory. TTL is
 * checked on every read (an absolute expiry timestamp is stored alongside
 * the value) and expired files are deleted as soon as they're accessed.
 */
final class FileStorage implements StorageInterface
{
    private readonly string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');

        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create rate limiter storage directory: {$this->directory}");
        }
    }

    public function get(string $key): ?array
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->deleteFile($path);

            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('expiresAt', $decoded) || !array_key_exists('value', $decoded)) {
            $this->deleteFile($path);

            return null;
        }

        if ((float) $decoded['expiresAt'] < microtime(true)) {
            $this->deleteFile($path);

            return null;
        }

        return is_array($decoded['value']) ? $decoded['value'] : null;
    }

    public function set(string $key, array $value, int $ttlSeconds): void
    {
        $path = $this->pathFor($key);

        $payload = json_encode([
            'expiresAt' => microtime(true) + $ttlSeconds,
            'value' => $value,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Failed to encode rate limiter state for storage.');
        }

        file_put_contents($path, $payload, LOCK_EX);
    }

    private function deleteFile(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function pathFor(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
