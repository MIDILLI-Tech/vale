<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use ValeApp\Domain\Analytics\Entity\Pageview;
use ValeApp\Domain\Analytics\Repository\PageviewRepositoryInterface as PageviewRepo;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Infrastructure\Analytics\GeoIpResolver;
use ValeApp\Infrastructure\Analytics\UserAgentParser;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\IpAnonymizer;

final class PageviewMiddleware implements MiddlewareInterface
{
    private const VIEW_DEDUP_TTL_SECONDS = 1800;

    /** @var list<string>|null */
    private ?array $excludedPrefixes = null;

    public function __construct(
        private readonly PageviewRepo $pageviews,
        private readonly DbalSettingsRepository $settings,
        private readonly GeoIpResolver $geoIp,
        private readonly UserAgentParser $uaParser,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly Connection $db,
        private readonly CacheInterface $cache,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request);

        try {
            if ($this->shouldLogPageview($request)) {
                $this->track($request, $response);
            } elseif ($this->shouldCountView($request)) {
                $visitorId = $this->buildVisitorId($request);
                $this->incrementContentCounter($request->getUri()->getPath(), $visitorId);
            }
        } catch (\Throwable) {
        }

        return $response;
    }

    private function shouldLogPageview(ServerRequestInterface $request): bool
    {
        if (($this->settings->get('analytics_enabled', '1') ?? '1') !== '1') {
            return false;
        }

        return $this->passesCommonGuards($request);
    }

    private function shouldCountView(ServerRequestInterface $request): bool
    {
        return $this->passesCommonGuards($request);
    }

    private function passesCommonGuards(ServerRequestInterface $request): bool
    {
        $method = $request->getMethod();
        if ($method !== 'GET') {
            return false;
        }

        $uri    = $request->getUri();
        $path   = $uri->getPath();

        foreach ($this->getExcludedPrefixes() as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        $excludePaths = $this->settings->get('analytics_exclude_paths', '') ?? '';
        if ($excludePaths !== '') {
            $patterns = explode("\n", str_replace(["\r\n", "\r"], "\n", $excludePaths));
            foreach ($patterns as $pattern) {
                $pattern = trim($pattern);
                if ($pattern === '') {
                    continue;
                }
                if (str_contains($pattern, '*')) {
                    $quoted = preg_quote($pattern, '/');
                    $regex  = '/^' . str_replace(['\*', '/'], ['.*', '\/'], $quoted) . '$/';
                    if (preg_match($regex, $path)) {
                        return false;
                    }
                } elseif ($path === $pattern) {
                    return false;
                }
            }
        }

        if (($this->settings->get('analytics_exclude_admins', '1') ?? '1') === '1') {
            $sessionUser = $_SESSION['user_id'] ?? null;
            if ($sessionUser !== null) {
                try {
                    $row = $this->db->fetchOne(
                        'SELECT r.slug FROM ' . VL_DB_PREFIX . 'user_roles ur'
                        . ' JOIN ' . VL_DB_PREFIX . 'roles r ON r.id = ur.role_id'
                        . ' WHERE ur.user_id = ? LIMIT 1',
                        [$sessionUser],
                    );
                    $role = is_array($row) ? ($row['slug'] ?? null) : null;
                    if ($role === 'admin' || $role === 'super_admin') {
                        return false;
                    }
                } catch (\Throwable) {
                    // Schema not available (e.g. pre-migration state) — count the visit
                }
            }
        }

        return true;
    }

    /** @return list<string> */
    private function getExcludedPrefixes(): array
    {
        if ($this->excludedPrefixes !== null) {
            return $this->excludedPrefixes;
        }

        $refData   = require __DIR__ . '/../../config/reference-data.php';
        $prefixes  = $refData['analytics_excluded_prefixes'] ?? [];

        return $this->excludedPrefixes = array_values(array_filter(array_map('strval', $prefixes)));
    }

    private function track(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $uri     = $request->getUri();
        $path    = $uri->getPath();
        $query   = $uri->getQuery();
        $pageUrl = $path . ($query !== '' ? '?' . $query : '');

        [$utmSource, $utmMedium, $utmCampaign, $utmTerm, $utmContent] = $this->extractUtm($request);

        $serverParams = $request->getServerParams();
        $rawIp        = vl_client_ip($request);
        $ua           = $serverParams['HTTP_USER_AGENT'] ?? null;

        $ipMode = $this->settings->get('ip_privacy_mode', 'mask') ?? 'mask';
        $anonIp = $this->ipAnonymizer->anonymize($rawIp, $ipMode);

        $visitorId = $this->buildVisitorId($request);

        $referrer       = $serverParams['HTTP_REFERER'] ?? null;
        $referrerDomain = null;
        if ($referrer !== null && $referrer !== '') {
            $parsed = parse_url($referrer);
            if ($parsed !== false && !empty($parsed['host'])) {
                $siteHost       = $uri->getHost();
                $referrerDomain = $parsed['host'] !== $siteHost ? $parsed['host'] : 'direct';
            }
        }
        if ($referrerDomain === null) {
            $referrerDomain = 'direct';
        }

        $geoMode   = $this->settings->get('analytics_geolocation', 'none') ?? 'none';
        $fileModes = [
            GeoIpResolver::MODE_IP2LOCATION_COUNTRY,
            GeoIpResolver::MODE_IP2LOCATION_CITY,
            GeoIpResolver::MODE_MAXMIND_CITY,
            GeoIpResolver::MODE_DBIP_CITY,
        ];
        if (in_array($geoMode, $fileModes, true)) {
            $storageDir = dirname(__DIR__, 2) . '/storage';
            $resolver   = new GeoIpResolver($storageDir);
            $resolver->configure($geoMode);
        } else {
            $resolver = $this->geoIp;
            $resolver->configure($geoMode);
        }
        $geo = $resolver->resolve($rawIp, $request);

        $browser     = $this->uaParser->parseBrowser($ua);
        $deviceType  = $this->uaParser->parseDevice($ua);
        $statusCode  = $response->getStatusCode();

        $acceptLang = $serverParams['HTTP_ACCEPT_LANGUAGE'] ?? null;
        $language   = null;
        if ($acceptLang !== null && $acceptLang !== '' && extension_loaded('intl')) {
            $locale = \Locale::acceptFromHttp($acceptLang);
            if ($locale !== false) {
                $language = strtolower(\Locale::getPrimaryLanguage($locale));
            }
        }

        $pageTitle = null;
        $pageview  = new Pageview(
            $pageUrl,
            $visitorId,
            $pageTitle,
            $referrer,
            $referrerDomain,
            $geo['country'] ?? null,
            $geo['city']    ?? null,
            $language,
            $browser,
            $deviceType,
            $statusCode,
            $utmSource,
            $utmMedium,
            $utmCampaign,
            $utmTerm,
            $utmContent,
            $path,
        );

        $this->pageviews->save($pageview);

        $this->incrementContentCounter($path, $visitorId);
    }

    /**
     * Reads the five standard UTM parameters from the query string. Values are
     * trimmed and empty ones become null so the DB keeps a single "absent"
     * representation (rendered as "(not set)" in the dashboard).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}
     */
    private function extractUtm(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();

        return [
            $this->utmParam($params, 'utm_source'),
            $this->utmParam($params, 'utm_medium'),
            $this->utmParam($params, 'utm_campaign'),
            $this->utmParam($params, 'utm_term'),
            $this->utmParam($params, 'utm_content'),
        ];
    }

    /** @param array<string, mixed> $params */
    private function utmParam(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function buildVisitorId(ServerRequestInterface $request): string
    {
        $ip           = vl_client_ip($request);
        $serverParams = $request->getServerParams();
        $ua           = $serverParams['HTTP_USER_AGENT'] ?? null;

        $ipMode = $this->settings->get('ip_privacy_mode', 'mask') ?? 'mask';
        $anonIp = $this->ipAnonymizer->anonymize($ip, $ipMode);

        return $anonIp !== null
            ? hash('sha256', $anonIp . '|' . ($ua !== null ? substr($ua, 0, 50) : ''))
            : hash('sha256', ($ua ?? '') . '|' . random_bytes(8));
    }

    private function incrementContentCounter(string $path, string $visitorId): void
    {
        $p = VL_DB_PREFIX;

        // Blog posts
        $rawBlog    = (string) $this->settings->get('routing_blog_prefix', '/blog');
        $blogPrefix = '/' . ltrim($rawBlog, '/') . '/';
        if (str_starts_with($path, $blogPrefix)) {
            $slug = strtok(substr($path, strlen($blogPrefix)), '/');
            if ($slug !== false && $slug !== '' && $this->markViewed($visitorId, $slug)) {
                $this->db->executeStatement(
                    "UPDATE {$p}posts SET view_count = view_count + 1"
                    . " WHERE slug = ? AND status = 'published'",
                    [$slug],
                );
            }
            return;
        }

        // Shop products
        $rawShop    = (string) $this->settings->get('routing_shop_prefix', '/shop');
        $shopPrefix = '/' . ltrim($rawShop, '/') . '/';
        if (str_starts_with($path, $shopPrefix)) {
            $slug = strtok(substr($path, strlen($shopPrefix)), '/');
            if ($slug !== false && $slug !== '' && $this->markViewed($visitorId, $slug)) {
                $this->db->executeStatement(
                    "UPDATE {$p}products SET view_count = view_count + 1"
                    . " WHERE slug = ? AND status = 'published'",
                    [$slug],
                );
            }
            return;
        }

        // Custom content types
        $cpts = $this->db->fetchAllAssociative(
            "SELECT slug, route_prefix FROM {$p}content_types"
            . " WHERE is_system = FALSE AND is_active = TRUE"
        );
        foreach ($cpts as $cpt) {
            $basePath  = ContentType::buildPublicBasePath(
                $cpt['route_prefix'] ?? null,
                (string) $cpt['slug'],
            );
            $cptPrefix = rtrim($basePath, '/') . '/';
            if (str_starts_with($path, $cptPrefix)) {
                $slug = strtok(substr($path, strlen($cptPrefix)), '/');
                if ($slug !== false && $slug !== '' && $this->markViewed($visitorId, $slug)) {
                    $this->db->executeStatement(
                        "UPDATE {$p}posts SET view_count = view_count + 1"
                        . " WHERE slug = ? AND status = 'published' AND content_type = ?",
                        [$slug, $cpt['slug']],
                    );
                }
                return;
            }
        }
    }

    /**
     * Marks a slug as viewed by the given visitor within the dedup window.
     * Returns true if this is the first view in the window (counter should be
     * incremented); false if the visitor already counted this slug recently.
     */
    private function markViewed(string $visitorId, string $slug): bool
    {
        $key = 'view_count_dedup:' . hash('sha256', $visitorId . '|' . $slug);
        if ($this->cache->has($key)) {
            return false;
        }

        return $this->cache->set($key, time(), self::VIEW_DEDUP_TTL_SECONDS);
    }
}
