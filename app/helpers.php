<?php

declare(strict_types=1);
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Domain\Content\RoutingPrefixTypes;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Vale global helper functions.
 *
 * Loaded via bootstrap/app.php. Provides short convenience functions.
 */

if (!function_exists('__')) {
    /**
     * Translate a string with optional replacements.
     */
    function __(string $text, array $replacements = []): string
    {
        $translator = Translator::getInstance();
        if ($translator !== null) {
            return $translator->get($text, $replacements);
        }

        $translated = $text;
        foreach ($replacements as $key => $value) {
            $translated = str_replace($key, $value, $translated);
        }
        return $translated;
    }
}

if (!function_exists('env')) {
    /**
     * Get an environment variable with an optional default.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        $stringValue = strtolower((string) $value);
        if ($stringValue === 'null' || $stringValue === '(null)') {
            return null;
        }
        if ($stringValue === 'empty' || $stringValue === '(empty)') {
            return '';
        }

        // Handle boolean strings (true, false, yes, no, on, off, 1, 0)
        // Only convert to bool if it's NOT an empty string, to avoid filter_var returning false for ""
        if ($value !== '') {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool !== null) {
                return $bool;
            }
        }

        return $value;
    }
}

if (!function_exists('client_side_search_fetch_limit')) {
    /**
     * Max records sent to the browser before switching to server-side
     * search/pagination. Configured via CLIENT_SIDE_SEARCH_FETCH_LIMIT.
     */
    function client_side_search_fetch_limit(): int
    {
        static $limit = null;
        if ($limit === null) {
            $config = require base_path('config' . DIRECTORY_SEPARATOR . 'app.php');
            $limit  = max(1, (int) ($config['client_side_search_fetch_limit'] ?? 100));
        }
        return $limit;
    }
}

if (!function_exists('vl_is_trusted_proxy')) {
    /**
     * Determine whether an IP address matches the trusted proxy configuration.
     * Supports exact IPs, wildcards (*, 0.0.0.0/0, ::/0), and IPv4 / IPv6 CIDR ranges.
     */
    function vl_is_trusted_proxy(string $remoteAddr, ?string $trustedProxiesConfig = null): bool
    {
        $config = $trustedProxiesConfig ?? (string) env('TRUSTED_PROXIES', '');
        $config = trim($config);
        if ($config === '') {
            return false;
        }

        $proxies = array_map('trim', explode(',', $config));

        foreach ($proxies as $proxy) {
            if ($proxy === '') {
                continue;
            }

            if ($proxy === '*' || $proxy === '0.0.0.0/0' || $proxy === '::/0') {
                return true;
            }

            if ($remoteAddr === $proxy) {
                return true;
            }

            if (str_contains($proxy, '/')) {
                [$subnet, $maskBitsStr] = explode('/', $proxy, 2);
                $subnet                 = trim($subnet);
                $maskBits               = (int) trim($maskBitsStr);

                $ipBin     = @inet_pton($remoteAddr);
                $subnetBin = @inet_pton($subnet);

                if ($ipBin === false || $subnetBin === false) {
                    continue;
                }

                $ipBytes     = strlen($ipBin);
                $subnetBytes = strlen($subnetBin);

                if ($ipBytes !== $subnetBytes) {
                    continue;
                }

                $maxBits = $ipBytes * 8;
                if ($maskBits < 0 || $maskBits > $maxBits) {
                    continue;
                }

                $match          = true;
                $maskBitsRemain = $maskBits;
                for ($i = 0; $i < $ipBytes; $i++) {
                    if ($maskBitsRemain >= 8) {
                        $mask = 0xFF;
                        $maskBitsRemain -= 8;
                    } elseif ($maskBitsRemain > 0) {
                        $mask           = (0xFF << (8 - $maskBitsRemain)) & 0xFF;
                        $maskBitsRemain = 0;
                    } else {
                        $mask = 0x00;
                    }

                    if ((ord($ipBin[$i]) & $mask) !== (ord($subnetBin[$i]) & $mask)) {
                        $match = false;
                        break;
                    }
                }

                if ($match) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('vl_client_ip')) {
    /**
     * Resolve the real client IP for rate limiting and logging.
     *
     * Uses proxy headers ONLY when REMOTE_ADDR matches TRUSTED_PROXIES — an
     * untrusted connection must not let clients spoof their way past limits.
     * Prefers CF-Connecting-IP (set by Cloudflare, not client-spoofable
     * behind Cloudflare) over X-Forwarded-For.
     */
    function vl_client_ip(ServerRequestInterface $request): string
    {
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';

        if (vl_is_trusted_proxy($remoteAddr)) {
            $connectingIp = trim($request->getHeaderLine('CF-Connecting-IP'));
            if ($connectingIp !== '' && filter_var($connectingIp, FILTER_VALIDATE_IP)) {
                return $connectingIp;
            }
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                $clientIp = trim(explode(',', $forwarded)[0]);
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }
        }

        return $remoteAddr;
    }
}

if (!function_exists('base_path')) {
    /**
     * Get the project root path.
     */
    function base_path(string $path = ''): string
    {
        static $root = null;
        if ($root === null) {
            $root = dirname(__DIR__);
        }
        return $root . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the storage directory path.
     */
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('public_path')) {
    /**
     * Get the public directory path.
     */
    function public_path(string $path = ''): string
    {
        return base_path('public' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('config_path')) {
    /**
     * Get the config directory path.
     */
    function config_path(string $path = ''): string
    {
        return base_path('config' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('e')) {
    /**
     * HTML-escape a string (shortcut for htmlspecialchars).
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('__require_app_url')) {
    /**
     * Canonical base URL (APP_URL). The application refuses to boot without it,
     * so callers must never fall back to the request's Host header.
     * @internal
     */
    function __require_app_url(): string
    {
        $appUrl = rtrim(trim((string) env('APP_URL', '')), '/');

        if (!preg_match('~^https?://[^/\s]+~i', $appUrl)) {
            throw new \RuntimeException(
                'APP_URL must be set to an absolute URL (e.g. https://example.com). Set APP_URL in your .env file.'
            );
        }

        return $appUrl;
    }
}

if (!function_exists('__require_app_key')) {
    /**
     * Validated APP_KEY value. The application refuses to boot without it
     * because encrypted settings cannot be read or written.
     * @internal
     */
    function __require_app_key(): string
    {
        $appKey = trim((string) env('APP_KEY', ''));

        if ((strlen($appKey) !== 64 && strlen($appKey) !== 32) || preg_match('/^[0-9a-f]+$/i', $appKey) !== 1) {
            throw new \RuntimeException(
                'APP_KEY must be 64 or 32 hexadecimal characters. '
                . 'Run `php vale key:generate` and set APP_KEY in your .env file.'
            );
        }

        return $appKey;
    }
}

if (!function_exists('vl_respond_config_error')) {
    /**
     * Stop the request with a generic unavailable response.
     *
     * Details stay in the server log (stderr in Docker/CLI) and, when debug is
     * enabled, in storage/logs/errors.log — never in the public response.
     *
     * Runs before the DI container is built, so it must not depend on Twig,
     * the database, or any application service.
     *
     * @param string[] $messages
     */
    function vl_respond_config_error(array $messages): never
    {
        $logLine = '[Vale] Configuration error: ' . implode(' | ', $messages);
        error_log($logLine);

        if ((bool) env('APP_DEBUG', false)) {
            @file_put_contents(
                storage_path('logs/errors.log'),
                '[' . date('Y-m-d H:i:s') . '] ' . $logLine . PHP_EOL,
                FILE_APPEND,
            );
        }

        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo 'Service Unavailable. Please try again later.';

        exit;
    }
}

if (!function_exists('__app_base_path')) {
    /**
     * Internal helper to resolve base path from APP_URL.
     * @internal
     */
    function __app_base_path(): string
    {
        static $base = null;
        if ($base === null) {
            $appUrl = (string) env('APP_URL', '');
            if ($appUrl !== '') {
                $parsed = parse_url($appUrl);
                $base   = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
            } else {
                $base = '';
            }
        }
        return $base;
    }
}

if (!function_exists('admin_url')) {
    /**
     * Get an admin panel URL, respecting the APP_URL subdirectory.
     */
    function admin_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        return __app_base_path() . '/admin' . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('url')) {
    /**
     * Generate a URL with base path and optional locale prefix.
     * Mirrors the Twig url() function for use in PHP controllers.
     */
    function url(string $path = '', array $data = [], array $queryParams = []): string
    {
        if (
            str_starts_with($path, 'http://')  ||
            str_starts_with($path, 'https://') ||
            str_starts_with($path, '//')
        ) {
            return $path;
        }
        $resolvedPath = $path;
        if (!str_starts_with($path, '/')) {
            $resolvedPath = '/' . $path;
        }
        return site_url($resolvedPath, $data, $queryParams);
    }
}

if (!function_exists('plugin_asset_url')) {
    /**
     * Public URL of a file inside content/plugins/<slug>/assets/.
     *
     * Respects the APP_URL subdirectory (no locale prefix: assets are shared
     * by every content language).
     */
    function plugin_asset_url(string $slug, string $path = ''): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return __app_base_path() . '/plugin-assets/' . rawurlencode($slug) . '/' . $path;
    }
}

if (!function_exists('site_url')) {
    /**
     * Get a site URL, respecting the APP_URL subdirectory and current locale.
     */
    function site_url(string $path = '', array $data = [], array $queryParams = []): string
    {
        $base = __app_base_path();
        $path = ($path !== '' ? '/' . ltrim($path, '/') : '');

        // Stringify value objects (e.g. Slug) so http_build_query() renders
        // `?content_type=testa` instead of `?content_type[value]=testa`.
        $stringify = static function (mixed $value) use (&$stringify): mixed {
            if ($value instanceof \Stringable) {
                return (string) $value;
            }
            if (is_array($value)) {
                return array_map($stringify, $value);
            }
            return $value;
        };
        $queryParams = array_map($stringify, $queryParams);

        // If path already contains base path, strip it for processing. The
        // match must end on a segment boundary: on a '/vale' install
        // '/vale-locale-page' is a page of its own, and stripping it blindly
        // turned it into '-locale-page' once a locale prefix was applied.
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
            if ($path === '') {
                $path = '/';
            }
        }

        // 1. Return immediately for static assets (never prefix with locale)
        $cleanPath = ltrim($path, '/');
        if (
            $cleanPath === 'favicon.ico' ||
            preg_match('~^(uploads|assets|themes)(/|$)~i', $cleanPath)
        ) {
            $url = $base . $path;
            return !empty($queryParams) ? $url . '?' . http_build_query($queryParams) : $url;
        }

        $t         = Translator::getInstance();
        $s         = DbalSettingsRepository::getInstance();
        $multiLang = $s ? ($s->get('multi_language_content', '0') === '1') : false;

        $locale = $queryParams['lang'] ?? null;
        if ($locale === null) {
            $locale = $t ? $t->getLocale() : 'en';
        } else {
            unset($queryParams['lang']);
        }

        if ($multiLang && $locale && $s) {
            $defaultLocale = $s->get('default_locale', 'en');

            // Apply localized routing prefixes if necessary
            if ($locale !== $defaultLocale) {
                $prefixTypes = RoutingPrefixTypes::CONTENT_TYPES;
                $matched     = false;

                foreach ($prefixTypes as $type) {
                    $globalPrefixKey = 'routing_' . $type . '_prefix';
                    $globalPrefix    = $s->get($globalPrefixKey, ($type === 'page' ? '' : '/' . $type));
                    $globalPrefix    = '/' . ltrim((string) $globalPrefix, '/');

                    // If global prefix is root (/), we only match it if nothing else matched
                    if ($globalPrefix === '/' && $type === 'page') {
                        // For root page prefix, we effectively match the whole path if not already matched
                        // But we should only do this if a localized prefix actually exists
                        $localPrefixKey = $globalPrefixKey . '_' . $locale;
                        $localPrefix    = $s->get($localPrefixKey);
                        if ($localPrefix !== null && $localPrefix !== '' && $localPrefix !== '/') {
                            $localPrefix = '/' . ltrim($localPrefix, '/');
                            $path        = $localPrefix . $path;
                            $matched     = true;
                        }
                        continue;
                    }

                    if ($path === $globalPrefix || str_starts_with($path, $globalPrefix . '/')) {
                        $localPrefixKey = $globalPrefixKey . '_' . $locale;
                        $localPrefix    = $s->get($localPrefixKey);

                        if ($localPrefix !== null) {
                            $localPrefix = '/' . ltrim($localPrefix, '/');
                            $suffix      = substr($path, strlen($globalPrefix));
                            $path        = $localPrefix . $suffix;
                        }
                        $matched = true;
                        break; // Only one prefix can match
                    }
                }
            }

            // Prefix with locale code if it's not the default locale
            if ($locale !== $defaultLocale) {
                $supported = $t ? $t->getSupportedLocales() : [];
                if (in_array($locale, $supported, true)) {
                    // Avoid double prefixing if path already starts with locale
                    $trimmedPath = ltrim($path, '/');
                    if ($trimmedPath !== $locale && !str_starts_with($trimmedPath, $locale . '/')) {
                        $path = '/' . $locale . '/' . ltrim($path, '/');
                    }
                }
            }
        }

        $url = $base . ($path === '' ? '/' : $path);

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }
}

if (!function_exists('absolute_url')) {
    function absolute_url(string $path = '', array $data = [], array $queryParams = []): string
    {
        $site = site_url($path, $data, $queryParams);
        $base = (string) env('APP_URL', '');

        if ($base === '') {
            return $site;
        }

        if (!preg_match('~^https?://~i', $base)) {
            $base = 'https://' . $base;
        }

        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? '';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return $scheme . '://' . $host . $port . $site;
    }
}
