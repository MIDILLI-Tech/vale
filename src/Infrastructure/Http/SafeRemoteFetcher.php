<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Http;

use ValeApp\Infrastructure\Security\PrivateNetworkGuard;

/**
 * Fetches remote HTTP(S) resources while following redirects manually, so every
 * hop can be re-validated with PrivateNetworkGuard before connecting. A plain
 * fopen() follows redirects internally and would therefore allow an attacker
 * controlled remote server to smuggle a request to a private/internal address
 * (SSRF via redirect).
 *
 * Shared by the download proxy (DownloadController), the remote media importer
 * (RemoteImageImporter) and the WordPress importer so the hardening lives in
 * exactly one place.
 *
 * The redirect limit, manual redirect following and the scheme check always
 * apply. Only the private-range guard can be waived, and only for a
 * deliberately trusted source (see $allowPrivateHosts).
 */
final class SafeRemoteFetcher
{
    /** Number of redirects followed before giving up. */
    public const MAX_REDIRECTS = 5;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * Opens a single connection without following redirects.
     * Signature: fn(string $url, string $userAgent, int $timeout): array
     * with keys status (int), location (?string), headers (list<string>),
     * stream (resource|null). Injectable for tests.
     *
     * @var \Closure
     */
    private readonly \Closure $opener;

    /**
     * @param bool $allowPrivateHosts Waive the private/reserved range guard.
     *                                Only for fetches of URLs supplied by a
     *                                trusted operator (e.g. a local WordPress
     *                                install being imported), never for URLs
     *                                taken from untrusted content.
     */
    public function __construct(
        ?\Closure $opener = null,
        private readonly bool $allowPrivateHosts = false,
    ) {
        $this->opener = $opener ?? self::openStream(...);
    }

    /**
     * Opens a URL, following at most MAX_REDIRECTS redirects.
     *
     * @return array{stream: resource, url: string, status: int, headers: list<string>}
     *
     * @throws RemoteFetchException when the URL is refused or unreachable
     */
    public function open(string $url, string $userAgent = 'Vale CMS', int $timeout = 300): array
    {
        $currentUrl    = $url;
        $redirectsLeft = self::MAX_REDIRECTS;

        while ($redirectsLeft >= 0) {
            self::assertFetchableScheme($currentUrl);

            if (!$this->allowPrivateHosts && PrivateNetworkGuard::isPrivateHost($currentUrl)) {
                throw RemoteFetchException::privateHost();
            }

            $response = ($this->opener)($currentUrl, $userAgent, $timeout);

            if (in_array($response['status'], self::REDIRECT_STATUSES, true)) {
                if (is_resource($response['stream'])) {
                    fclose($response['stream']);
                }

                $redirectUrl = $response['location'] === null
                    ? null
                    : self::resolveRedirectUrl($currentUrl, $response['location']);

                if ($redirectUrl === null) {
                    throw RemoteFetchException::invalidRedirect();
                }

                $currentUrl = $redirectUrl;
                $redirectsLeft--;

                continue;
            }

            if ($response['status'] >= 400) {
                if (is_resource($response['stream'])) {
                    fclose($response['stream']);
                }

                throw RemoteFetchException::httpError($response['status']);
            }

            if (!is_resource($response['stream'])) {
                throw RemoteFetchException::connectionFailed();
            }

            return [
                'stream'  => $response['stream'],
                'url'     => $currentUrl,
                'status'  => $response['status'],
                'headers' => $response['headers'],
            ];
        }

        throw RemoteFetchException::tooManyRedirects();
    }

    /**
     * Resolves a Location header against the URL that produced it.
     * Returns null for empty values and non-HTTP(S) schemes.
     */
    public static function resolveRedirectUrl(string $baseUrl, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        // Any other absolute scheme (ftp:, file:, data:, …) is refused instead of
        // being misread as a relative path.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $location) === 1) {
            return null;
        }

        $parsed = parse_url($baseUrl);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        $origin = $parsed['scheme'] . '://' . $parsed['host']
            . (isset($parsed['port']) ? ':' . $parsed['port'] : '');

        // Protocol-relative: keep the base scheme.
        if (str_starts_with($location, '//')) {
            return $parsed['scheme'] . ':' . $location;
        }

        // Query-only: same path, replaced query string.
        if (str_starts_with($location, '?')) {
            return $origin . ($parsed['path'] ?? '/') . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        // POSIX-style dirname: dirname() returns a backslash for the root
        // directory on Windows, which would corrupt the resolved URL.
        $path      = $parsed['path'] ?? '/';
        $lastSlash = strrpos($path, '/');
        $dir       = $lastSlash === false ? '/' : (substr($path, 0, $lastSlash) ?: '/');

        return $origin . rtrim($dir, '/') . '/' . $location;
    }

    /**
     * Extracts the status code and Location header from raw response headers.
     * A later status line (proxy hops) resets an earlier Location.
     *
     * @param  list<string> $headers
     * @return array{0: int, 1: ?string}
     */
    public static function parseResponseHeaders(array $headers): array
    {
        $status   = 0;
        $location = null;

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\d+(?:\.\d+)?\s+(\d{3})#i', $header, $m) === 1) {
                $status   = (int) $m[1];
                $location = null;
            } elseif (preg_match('#^Location:\s*(.+)$#i', $header, $m) === 1) {
                $location = trim($m[1]);
            }
        }

        return [$status, $location];
    }

    private static function assertFetchableScheme(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw RemoteFetchException::invalidScheme();
        }
    }

    /**
     * Default opener: one GET request, no internal redirect following.
     *
     * @return array{status: int, location: ?string, headers: list<string>, stream: resource|null}
     */
    private static function openStream(string $url, string $userAgent, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => 'User-Agent: ' . $userAgent . "\r\n",
                'follow_location' => 0,
                'ignore_errors'   => true,
                'timeout'         => $timeout,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return ['status' => 0, 'location' => null, 'headers' => [], 'stream' => null];
        }

        $meta    = stream_get_meta_data($handle);
        $raw     = $meta['wrapper_data'] ?? [];
        $headers = [];

        if (is_array($raw)) {
            foreach ($raw as $header) {
                if (is_string($header)) {
                    $headers[] = $header;
                }
            }
        }

        [$status, $location] = self::parseResponseHeaders($headers);

        return [
            'status'   => $status,
            'location' => $location,
            'headers'  => $headers,
            'stream'   => $handle,
        ];
    }
}
