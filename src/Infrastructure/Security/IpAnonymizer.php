<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Security;

final class IpAnonymizer
{
    public const MODE_REMOVE = 'remove';
    public const MODE_MASK   = 'mask';
    public const MODE_FULL   = 'full';

    public function anonymize(?string $ip, string $mode = self::MODE_MASK): ?string
    {
        if ($ip === null || $ip === '' || $mode === self::MODE_REMOVE) {
            return null;
        }

        if ($mode === self::MODE_FULL) {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts    = explode('.', $ip);
            $parts[3] = '***';
            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts        = explode(':', $ip);
            $last         = count($parts) - 1;
            $parts[$last] = '****';
            return implode(':', $parts);
        }

        return $ip;
    }
}
