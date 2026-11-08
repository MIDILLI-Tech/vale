<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Cache;

/**
 * Full HTML page cache — flat file.
 *
 * Stores rendered HTML responses keyed by URL + user role hash.
 * Serves cached pages before the application stack runs.
 */
final class PageCache
{
    /** @param string[] $excludePatterns Glob-like URL patterns to never cache */
    public function __construct(
        private readonly string $directory,
        private readonly int $ttl = 86400,
        private readonly array $excludePatterns = [],
        private readonly bool $enabled = true,
    ) {
        if ($this->enabled && !is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if a cached page exists and is not expired.
     */
    public function get(string $url, string $role = 'guest'): ?string
    {
        if (!$this->enabled || $this->isExcluded($url)) {
            return null;
        }

        $path = $this->path($url, $role);
        if (!is_file($path)) {
            return null;
        }

        // Check TTL
        if ((time() - filemtime($path)) > $this->ttl) {
            @unlink($path);
            return null;
        }

        return file_get_contents($path) ?: null;
    }

    /**
     * Store an HTML response in cache.
     */
    public function put(string $url, string $html, string $role = 'guest'): bool
    {
        if (!$this->enabled || $this->isExcluded($url)) {
            return false;
        }

        $path = $this->path($url, $role);
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return file_put_contents($path, $html, LOCK_EX) !== false;
    }

    /**
     * Invalidate cache for a specific URL (all queries and roles).
     */
    public function invalidate(string $url): void
    {
        $parsed = parse_url($url);
        $path   = $parsed['path'] ?? '/';

        // Clear all query/role variants for this path
        $this->invalidateByPath($path);

        // Also clear variants with/without trailing slash to be safe
        if ($path !== '/') {
            $altPath = str_ends_with($path, '/') ? rtrim($path, '/') : $path . '/';
            $this->invalidateByPath($altPath);
        }
    }

    private function invalidateByPath(string $path): void
    {
        $pathHash = md5($path);
        $pattern  = $this->directory . '/' . $pathHash . '_*.html';
        foreach (glob($pattern) ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Flush all page cache.
     */
    public function flush(): int
    {
        $count = 0;
        $files = glob($this->directory . '/*.html');
        foreach ($files ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Total size of page cache in bytes.
     */
    public function getSize(): int
    {
        $size = 0;
        foreach (glob($this->directory . '/*.html') ?: [] as $file) {
            $size += filesize($file);
        }
        return $size;
    }

    // ── Internals ────────────────────────────────────────────────────

    private function path(string $url, string $role): string
    {
        // Key by path+query only — strips scheme/host/port so invalidation
        // works regardless of how the URL was constructed (full URI vs path).
        $parsed = parse_url($url);
        $path   = $parsed['path']  ?? '/';
        $query  = $parsed['query'] ?? '';

        return $this->directory . '/' . md5($path) . '_' . md5($query) . '_' . md5($role) . '.html';
    }

    private function isExcluded(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        foreach ($this->excludePatterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
