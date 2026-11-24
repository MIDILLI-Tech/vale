<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Security;

/**
 * File-based rate limiter.
 *
 * Tracks request counts per (key + IP) in flat files.
 * No Redis required. Suitable for shared hosting.
 *
 * Each key+IP owns one counter file. Every read-modify-write runs under an
 * exclusive flock() on that file, so concurrent requests serialise and share
 * one counter instead of each reading the same stale value and all passing.
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
     * @return bool True if attempt is ALLOWED, false if rate-limited.
     *              Also false when the counter file cannot be locked — the
     *              limit must never fail open.
     */
    public function attempt(string $key, string $ip, int $maxAttempts, int $windowSeconds = 60): bool
    {
        $allowed = $this->withLockedAttempts(
            $this->filePath($key, $ip),
            $windowSeconds,
            static function (array $attempts) use ($maxAttempts): array {
                if (count($attempts) >= $maxAttempts) {
                    return [$attempts, false];
                }

                $attempts[] = time();

                return [$attempts, true];
            },
        );

        return $allowed ?? false;
    }

    /**
     * Is the action currently locked out? (read-only check, no counting)
     *
     * @return bool True when the client has exhausted its attempts
     */
    public function blocked(string $key, string $ip, int $maxAttempts, int $windowSeconds = 60): bool
    {
        return count($this->readAttempts($this->filePath($key, $ip), $windowSeconds)) >= $maxAttempts;
    }

    /**
     * Record one attempt without checking the limit.
     */
    public function record(string $key, string $ip, int $windowSeconds = 60): void
    {
        $this->withLockedAttempts(
            $this->filePath($key, $ip),
            $windowSeconds,
            static function (array $attempts): array {
                $attempts[] = time();

                return [$attempts, null];
            },
        );
    }

    /**
     * Get remaining attempts.
     */
    public function remaining(string $key, string $ip, int $maxAttempts, int $windowSeconds = 60): int
    {
        return max(0, $maxAttempts - count($this->readAttempts($this->filePath($key, $ip), $windowSeconds)));
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
     * Reads the attempt list, hands it to $mutator and persists the list the
     * mutator returns — all while holding an exclusive lock, so the decision
     * the mutator makes is based on the same state it writes back.
     *
     * @param callable(int[]): array{0: int[], 1: mixed} $mutator
     * @return mixed The mutator's second return value, or null when the lock
     *               could not be acquired.
     */
    private function withLockedAttempts(string $file, int $windowSeconds, callable $mutator): mixed
    {
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }

            $attempts            = $this->parseAttempts((string) stream_get_contents($handle), $windowSeconds);
            [$attempts, $result] = $mutator($attempts);
            $this->writeAttempts($handle, $attempts);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return int[]
     */
    private function readAttempts(string $file, int $windowSeconds): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return [];
            }

            return $this->parseAttempts((string) stream_get_contents($handle), $windowSeconds);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return int[] Attempt timestamps still inside the window
     */
    private function parseAttempts(string $content, int $windowSeconds): array
    {
        $timestamps = json_decode($content, true);
        if (!is_array($timestamps)) {
            return [];
        }

        $cutoff = time() - $windowSeconds;

        return array_values(array_filter(
            $timestamps,
            static fn ($ts) => is_int($ts) && $ts > $cutoff,
        ));
    }

    /**
     * @param resource $handle
     * @param int[]    $attempts
     */
    private function writeAttempts($handle, array $attempts): void
    {
        $payload = json_encode(array_values($attempts));

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $payload === false ? '[]' : $payload);
        fflush($handle);
    }
}
