<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Analytics;

final class UserAgentParser
{
    private const DEVICE_DESKTOP = 'desktop';
    private const DEVICE_MOBILE  = 'mobile';
    private const DEVICE_TABLET  = 'tablet';

    private const BROWSER_UNKNOWN = 'Unknown';

    public function parseBrowser(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return self::BROWSER_UNKNOWN;
        }

        $ua = $userAgent;

        if (stripos($ua, 'Edg/') !== false || stripos($ua, 'Edge/') !== false) {
            return 'Edge';
        }
        if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera/') !== false) {
            return 'Opera';
        }
        if (stripos($ua, 'Chrome/') !== false) {
            return 'Chrome';
        }
        if (stripos($ua, 'Firefox/') !== false) {
            return 'Firefox';
        }
        if (stripos($ua, 'Safari/') !== false) {
            return 'Safari';
        }

        return self::BROWSER_UNKNOWN;
    }

    public function parseDevice(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return self::DEVICE_DESKTOP;
        }

        $ua = $userAgent;

        if (
            stripos($ua, 'iPad')       !== false
            || stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false
            || stripos($ua, 'Tablet')  !== false
            || stripos($ua, 'Silk')    !== false
        ) {
            return self::DEVICE_TABLET;
        }

        if (
            stripos($ua, 'Mobile')           !== false
            || stripos($ua, 'iPhone')        !== false
            || stripos($ua, 'iPod')          !== false
            || stripos($ua, 'Android')       !== false
            || stripos($ua, 'BlackBerry')    !== false
            || stripos($ua, 'Windows Phone') !== false
            || stripos($ua, 'Opera Mini')    !== false
            || stripos($ua, 'Opera Mobi')    !== false
        ) {
            return self::DEVICE_MOBILE;
        }

        return self::DEVICE_DESKTOP;
    }
}
