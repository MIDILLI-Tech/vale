<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 flat-file cache implementation.
 *
 * Each cache key is stored as a serialized PHP file containing
 * the expiration timestamp and the cached value.
 */
final class FileCache implements CacheInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly int $defaultTtl = 3600,
    ) {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return $default;
        }

        $data = $this->readFile($path);
        if ($data === null) {
            return $default;
        }

        // Expired?
        if ($data['expires'] > 0 && $data['expires'] < time()) {
            @unlink($path);
            return $default;
        }

        return $data['value'];
    }

    #[\Override]
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $seconds = $this->ttlToSeconds($ttl);
        $expires = $seconds > 0 ? time() + $seconds : 0;

        $content = serialize(['expires' => $expires, 'value' => $value]);

        return $this->writeFile($this->path($key), $content);
    }

    #[\Override]
    public function delete(string $key): bool
    {
        $path = $this->path($key);
        return !is_file($path) || @unlink($path);
    }

    #[\Override]
    public function clear(): bool
    {
        $files = glob($this->directory . '/*.cache');
        if ($files === false) {
            return false;
        }
        foreach ($files as $file) {
            @unlink($file);
        }
        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    #[\Override]
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $ok = false;
            }
        }
        return $ok;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $ok = false;
            }
        }
        return $ok;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /**
     * Get total size of cache directory in bytes.
     */
    public function getSize(): int
    {
        $size  = 0;
        $files = glob($this->directory . '/*.cache');
        if ($files !== false) {
            foreach ($files as $file) {
                $size += filesize($file);
            }
        }
        return $size;
    }

    // ── Internals ────────────────────────────────────────────────────

    private function path(string $key): string
    {
        // MD5 prevents filesystem-unfriendly characters
        return $this->directory . '/' . md5($key) . '.cache';
    }

    private function readFile(string $path): ?array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = @unserialize($content, ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            @unlink($path);
            return null;
        }

        return $data;
    }

    private function writeFile(string $path, string $content): bool
    {
        // Atomic write: write to temp file then rename
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        return rename($tmp, $path);
    }

    private function ttlToSeconds(\DateInterval|int|null $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }
        if ($ttl instanceof \DateInterval) {
            return (int) (new \DateTime())->add($ttl)->getTimestamp() - time();
        }
        return max(0, $ttl);
    }
}
