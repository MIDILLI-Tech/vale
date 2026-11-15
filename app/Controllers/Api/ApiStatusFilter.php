<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Api;

use ValeApp\Domain\Shared\Exception\ValidationException;

/**
 * Parses the ?status= filter shared by the /api/v1 list endpoints:
 * one status, a comma-separated combination, or "all" (no restriction).
 */
final class ApiStatusFilter
{
    /** Statuses any read-only token may list. */
    private const PUBLIC_STATUSES = ['published', 'unlisted'];

    /**
     * @param string[] $allowed
     * @return string|string[]|null Null means "no status restriction".
     * @throws ValidationException When a requested status is not in $allowed.
     */
    public static function parse(mixed $raw, array $allowed, ?string $default = 'published'): string|array|null
    {
        $parts = [];
        foreach ((array) $raw as $value) {
            foreach (explode(',', (string) $value) as $part) {
                $part = strtolower(trim($part));
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
        }

        $requested = array_values(array_unique($parts));

        if ($requested === []) {
            return $default;
        }

        // "all" means every status a client should sync; trashed rows stay
        // hidden unless they are requested by name.
        if (in_array('all', $requested, true)) {
            return array_values(array_diff($allowed, ['trash']));
        }

        $unknown = array_values(array_diff($requested, $allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Unknown status "%s". Allowed: %s, all.',
                    implode(', ', $unknown),
                    implode(', ', $allowed)
                ),
            ]);
        }

        return count($requested) === 1 ? $requested[0] : $requested;
    }

    /**
     * True when the status set reaches beyond publicly readable content, so
     * the token must also hold the endpoint's write ability.
     *
     * @param string|string[]|null $status
     */
    public static function needsWriteAccess(string|array|null $status): bool
    {
        if ($status === null) {
            return true;
        }

        foreach ((array) $status as $item) {
            if (!self::isPubliclyReadable($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True for statuses any token may read without the write ability.
     */
    public static function isPubliclyReadable(string $status): bool
    {
        return in_array($status, self::PUBLIC_STATUSES, true);
    }

    /**
     * Checks the token's comma-separated abilities; "*" grants everything.
     */
    public static function tokenHasAbility(mixed $abilities, string $ability): bool
    {
        $raw = trim((string) $abilities);
        if ($raw === '*') {
            return true;
        }

        return in_array($ability, array_map('trim', explode(',', $raw)), true);
    }
}
