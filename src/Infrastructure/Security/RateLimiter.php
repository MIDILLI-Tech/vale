<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Security;

/**
 * File-based rate limiter.
 *
 * Tracks request counts per (key + IP) in flat files.
 * No Redis required. Suitable for shared hosting.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $storagePath,
    ) {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0775, true);
        }
    }

    /**
     * Check if the action is rate-limited.
     *
     * @param string $key       Action identifier (e.g. "login", "form")
     * @param string $ip        Client IP address
     * @param int    $maxAttempts Max allowed attempts per window
     * @param int    $windowSeconds Time window in seconds (default: 60)
     * @return bool True if attempt is ALLOWED, false if rate-limited
     */
    public function attempt(string $key, string $ip, int $maxAttempts, int $windowSeconds = 60): bool
    {
        $file = $this->filePath($key, $ip);
        $now  = time();

        $attempts = $this->loadAttempts($file, $now, $windowSeconds);

        if (count($attempts) >= $maxAttempts) {
            return false;
        }

        $attempts[] = $now;
        $this->saveAttempts($file, $attempts);

        return true;
    }

    /**
     * Is the action currently locked out? (read-only check, no counting)
     *
     * @return bool True when the client has exhausted its attempts
     */
    public function blocked(string $key, string $ip, int $maxAttempts, int $windowSeconds = 60): bool
    {
        return count($this->loadAttempts($this->filePath($key, $ip), time(), $windowSeconds)) >= $maxAttempts;
    }

    /**
     * Record one attempt without checking the limit.
     */
    public function record(string $key, string $ip, int $windowSeconds = 60): void
    {
        $file       = $this->filePath($key, $ip);
        $now        = time();
        $attempts   = $this->loadAttempts($file, $now, $windowSeconds);
        $attempts[] = $now;
        $this->saveAttempts($file, $attempts);
    }

    /**
     * Get remaining attempts.
     */
    public function remaining(string $key, string $ip, int $maxAttempts, int $windowSeconds = 60): int
    {
        $file     = $this->filePath($key, $ip);
        $attempts = $this->loadAttempts($file, time(), $windowSeconds);

        return max(0, $maxAttempts - count($attempts));
    }

    /**
     * Clear all rate limit data for a key + IP.
     */
    public function clear(string $key, string $ip): void
    {
        $file = $this->filePath($key, $ip);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    // ── Internals ────────────────────────────────────────────────────

    private function filePath(string $key, string $ip): string
    {
        $safeKey = preg_replace('/[^a-z0-9_-]/i', '_', $key);
        return $this->storagePath . '/' . $safeKey . '_' . md5($ip) . '.rate';
    }

    /**
     * Load valid (non-expired) attempts from file.
     *
     * @return int[] Timestamps of valid attempts
     */
    private function loadAttempts(string $file, int $now, int $windowSeconds): array
    {
        if (!is_file($file)) {
            return [];
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $timestamps = json_decode($content, true);
        if (!is_array($timestamps)) {
            return [];
        }

        // Keep only attempts within the window
        $cutoff = $now - $windowSeconds;
        return array_values(array_filter($timestamps, fn (int $ts) => $ts > $cutoff));
    }

    private function saveAttempts(string $file, array $attempts): void
    {
        file_put_contents($file, json_encode($attempts), LOCK_EX);
    }
}
