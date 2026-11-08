<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Short-lived versioned cache for admin list search queries.
 *
 * Values are stored as JSON strings over scalar-only row arrays because the
 * FileCache backend unserializes with allowed_classes=false, which cannot
 * revive entity objects. Callers map entities to plain DB-row arrays on
 * write and re-hydrate with ::fromRow() on hit.
 *
 * Staleness is handled two ways: a short TTL (repeated A↔B search switches
 * stay fast) plus a per-namespace version counter bumped on every mutation
 * (upload/update/delete), so listings never serve rows older than the last
 * write without enumerating or clearing unrelated cache entries.
 */
final class AdminSearchCache
{
    private const VERSION_SUFFIX = ':version';

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $keyParts normalized filter + paging parts
     * @return array{total: int, rows: array<int, array<string, mixed>>}|null
     */
    public function get(string $namespace, array $keyParts): ?array
    {
        try {
            $raw = $this->cache->get($this->key($namespace, $keyParts));
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['total'], $decoded['rows'])) {
            return null;
        }
        if (!is_array($decoded['rows'])) {
            return null;
        }
        return ['total' => (int) $decoded['total'], 'rows' => $decoded['rows']];
    }

    /**
     * @param array<string, mixed> $keyParts normalized filter + paging
     * @param array<int, array<string, mixed>> $rows scalar-only row arrays
     */
    public function set(
        string $namespace,
        array $keyParts,
        int $total,
        array $rows,
        int $ttl = 120,
    ): void {
        try {
            $this->cache->set(
                $this->key($namespace, $keyParts),
                json_encode(['total' => $total, 'rows' => $rows], JSON_UNESCAPED_UNICODE),
                $ttl,
            );
        } catch (\Throwable) {
            // Cache is best-effort; listing pages work without it.
        }
    }

    public function bump(string $namespace): void
    {
        try {
            $versionKey = $namespace . self::VERSION_SUFFIX;
            $version    = (int) $this->cache->get($versionKey, 0);
            $this->cache->set($versionKey, $version + 1);
        } catch (\Throwable) {
            // Cache is best-effort; listing pages work without it.
        }
    }

    /**
     * Store an entity list (e.g. wide Product rows that would need dozens
     * of getters to map losslessly) as an opaque blob. Revival is
     * restricted to $allowedClasses; anything else stays an
     * __PHP_Incomplete_Class and the caller must reject the payload.
     * Safe by construction: the cache directory is server-local, entries
     * live at most $ttl seconds, and domain entities carry no magic
     * methods or closures.
     *
     * @param array<string, mixed> $keyParts normalized filter + paging parts
     */
    public function setObjects(
        string $namespace,
        array $keyParts,
        int $total,
        array $objects,
        int $ttl = 120,
    ): void {
        try {
            $this->cache->set(
                $this->key($namespace, $keyParts),
                json_encode([
                    'total' => $total,
                    'items' => base64_encode(serialize($objects)),
                ], JSON_UNESCAPED_UNICODE),
                $ttl,
            );
        } catch (\Throwable) {
            // Cache is best-effort; listing pages work without it.
        }
    }

    /**
     * @param array<string, mixed> $keyParts normalized filter + paging parts
     * @param list<class-string>   $allowedClasses revival allowlist
     * @return array{total: int, items: array<int, mixed>}|null
     */
    public function getObjects(string $namespace, array $keyParts, array $allowedClasses): ?array
    {
        try {
            $raw = $this->cache->get($this->key($namespace, $keyParts));
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['total'], $decoded['items'])) {
            return null;
        }
        if (!is_string($decoded['items'])) {
            return null;
        }
        $binary = base64_decode($decoded['items'], true);
        if ($binary === false) {
            return null;
        }
        try {
            $items = unserialize($binary, ['allowed_classes' => $allowedClasses]);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($items)) {
            return null;
        }
        return ['total' => (int) $decoded['total'], 'items' => $items];
    }

    /** @param array<string, mixed> $keyParts */
    private function key(string $namespace, array $keyParts): string
    {
        $version = 0;
        try {
            $version = (int) $this->cache->get($namespace . self::VERSION_SUFFIX, 0);
        } catch (\Throwable) {
            // Best-effort.
        }
        $encoded = (string) json_encode($keyParts, JSON_UNESCAPED_UNICODE);
        return $namespace . ':v' . $version . ':' . md5($encoded);
    }
}
