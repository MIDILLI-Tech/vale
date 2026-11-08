<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Security;

/**
 * Blocks outbound requests to private/internal/reserved network targets.
 * Shared by webhooks and any feature that fetches user-supplied URLs.
 */
final class PrivateNetworkGuard
{
    public static function isPrivateHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            return true;
        }
        $host = ltrim(rtrim($host, ']'), '[');

        $asIp = filter_var($host, FILTER_VALIDATE_IP);
        if ($asIp !== false) {
            return !filter_var($asIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        $lower = strtolower($host);
        if (
            $lower === 'localhost'
            || str_ends_with($lower, '.local')
            || str_ends_with($lower, '.internal')
            || str_ends_with($lower, '.localhost')
        ) {
            return true;
        }

        $resolved = gethostbyname($host);
        if ($resolved === $host) {
            // Resolution failed — block to be safe
            return true;
        }
        return !filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
