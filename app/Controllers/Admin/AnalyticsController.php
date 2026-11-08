<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\Domain\Analytics\Repository\PageviewRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\DateRange;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class AnalyticsController
{
    /** Default page size of every analytics list and the AJAX "load more" batch. */
    public const LIST_LIMIT = 100;

    /** Upper bound for a single AJAX page. */
    private const MAX_LIST_LIMIT = 500;

    /**
     * Top Products is the only tile grid, so it opens with exactly two rows
     * (five tiles each on desktop) and streams the rest through the sentinel.
     */
    private const PRODUCTS_PREVIEW = 10;

    /** AJAX list identifiers accepted by listRows(). */
    private const ALLOWED_LISTS = [
        'status-codes',
        'top-referrers',
        'countries',
        'cities',
        'languages',
        'top-pages',
        'entry-pages',
        'exit-pages',
        'top-posts',
        'top-products',
        'top-cpt',
        'utm-source',
        'utm-medium',
        'utm-campaign',
        'utm-term',
        'utm-content',
        'utm-destination',
    ];

    private const STATUS_SERIES = ['3xx', '4xx', '5xx'];

    /**
     * UTM dimensions in render order. The list is also the query-parameter
     * whitelist for the card's facet filters; panel titles live in
     * admin/analytics/rows/utm_body.twig so they stay statically translatable.
     */
    private const UTM_PANELS = ['source', 'medium', 'campaign', 'term', 'content', 'destination'];

    /**
     * Panels that always render, even when empty, so the card keeps a stable
     * layout on sites that never set utm_term / utm_content.
     */
    private const UTM_CORE_PANELS = ['source', 'medium', 'campaign', 'destination'];

    /** Wire token representing the "(not set)" bucket in facet state. */
    private const UTM_NOT_SET = '__not_set__';

    /** Numeric columns the AJAX lists may be sorted by. */
    private const ALLOWED_SORTS = ['views', 'duration', 'last_seen', 'session_duration'];


    public function __construct(
        private readonly Twig $twig,
        private readonly PageviewRepositoryInterface $pageviews,
        private readonly DbalSettingsRepository $settings,
        private readonly Connection $db,
        private readonly ContentTypeRepositoryInterface $contentTypes,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($this->settings->get('analytics_enabled', '1') ?? '1') !== '1') {
            return $response->withHeader('Location', admin_url('/settings?tab=analytics'))->withStatus(302);
        }

        $query = $request->getQueryParams();
        $days  = max(1, min(365, (int) ($query['days'] ?? 7)));

        $retentionDays = max(1, (int) ($this->settings->get('analytics_retention_days', '90') ?? 90));
        $range         = $this->resolveRange($query, $retentionDays);

        $excludePaths = $this->buildExcludedPaths();

        $totalViews  = $this->pageviews->countDays($days, $range);
        $todayViews  = $this->pageviews->countToday();
        $todayUnique = $this->pageviews->countUniqueToday();
        $online      = $this->pageviews->countOnline(15);

        $topPages       = $this->visible($this->pageviews->topPages(self::LIST_LIMIT, $days, $excludePaths, $range));
        $topReferrers   = $this->visible($this->pageviews->topReferrers(self::LIST_LIMIT, $days, $range));
        $sessionMetrics = $this->pageviews->getSessionMetrics($days, $range);
        $entryPages     = $this->visible($this->pageviews->entryPages(self::LIST_LIMIT, $days, $excludePaths, $range));
        $exitPages      = $this->visible($this->pageviews->exitPages(self::LIST_LIMIT, $days, $excludePaths, $range));
        $utmStats       = $this->pageviews->utmStats($days, $range, self::LIST_LIMIT);
        $browsers       = $this->pageviews->browserStats($days, $range);
        $devices        = $this->pageviews->deviceStats($days, $range);
        $countries      = $this->visible($this->pageviews->countryStats($days, $range, self::LIST_LIMIT));
        $cities         = $this->visible($this->pageviews->cityStats($days, $range, self::LIST_LIMIT));
        $languages      = $this->visible($this->pageviews->languageStats($days, $range, self::LIST_LIMIT));
        $dailyStats     = $range !== null
            ? $this->pageviews->dailyStats($days, $range)
            : $this->pageviews->dailyStats(min(90, $days));
        $statusCodeStats  = $this->pageviews->statusCodeStats($days, $range, self::LIST_LIMIT, 0);
        $statusCodeCounts = $this->pageviews->statusCodeCounts($days, $range);

        $statusCodeStats = array_map(fn (array $rows): array => $this->visible($rows), $statusCodeStats);

        $topPosts     = array_slice($this->topContentRows('post', self::LIST_LIMIT, $days, $range), 0, self::LIST_LIMIT);

        // Products render as a tile grid, so the first paint shows exactly two
        // tile rows (the load-more sentinel streams the rest) instead of a
        // partial third row cut off by the card's max height. The repository
        // fetches one extra row so "has more" is known without a COUNT.
        $productRows      = $this->topContentRows('product', self::PRODUCTS_PREVIEW, $days, $range);
        $topProducts      = array_slice($productRows, 0, self::PRODUCTS_PREVIEW);
        $topProductsMore  = count($productRows) > self::PRODUCTS_PREVIEW;

        $customTypes = $this->contentTypes->findActiveCustom();
        $topCustom   = [];
        foreach ($customTypes as $ct) {
            $slug  = (string) $ct->getSlug();
            $items = array_slice(
                $this->topContentRows('custom', self::LIST_LIMIT, $days, $range, 0, $slug),
                0,
                self::LIST_LIMIT
            );
            if (!empty($items)) {
                $topCustom[] = [
                    'type'  => $ct,
                    'items' => $items,
                ];
            }
        }

        $devices = array_map(static fn (array $d) => [
            'device_type' => ucfirst($d['device_type'] ?? ''),
            'views'       => $d['views'],
        ], $devices);

        return $this->twig->render($response, 'admin/analytics/index.twig', [
            'title'               => 'Analytics',
            'days'                => $days,
            'range_from'          => $range?->from->format('Y-m-d'),
            'range_to'            => $range !== null ? $range->toExclusive->modify('-1 day')->format('Y-m-d') : null,
            'range_min'           => (new \DateTimeImmutable("-{$retentionDays} days"))->format('Y-m-d'),
            'range_max'           => (new \DateTimeImmutable())->format('Y-m-d'),
            'total_views'         => $totalViews,
            'today_views'         => $todayViews,
            'today_unique'        => $todayUnique,
            'online'              => $online,
            'top_pages'           => $topPages,
            'top_referrers'       => $topReferrers,
            'avg_duration'        => $sessionMetrics['avg_duration'],
            'bounce_rate'         => $sessionMetrics['bounce_rate'],
            'avg_pages'           => $sessionMetrics['avg_pages'],
            'entry_pages'         => $entryPages,
            'exit_pages'          => $exitPages,
            'utm_panels'          => $this->utmPanels($utmStats, []),
            'utm_filters'         => [],
            'utm_dir'             => 'desc',
            'browsers'            => $browsers,
            'devices'             => $devices,
            'countries'           => $countries,
            'cities'              => $cities,
            'languages'           => $languages,
            'daily_stats'         => $dailyStats,
            'status_code_stats'   => $statusCodeStats,
            'status_code_counts'  => $statusCodeCounts,
            'top_posts'           => $topPosts,
            'top_products'        => $topProducts,
            'top_products_more'   => $topProductsMore,
            'products_preview'    => self::PRODUCTS_PREVIEW,
            'top_custom'          => $topCustom,
            'analytics_enabled'   => $this->settings->get('analytics_enabled', '1'),
            'blog_enabled'        => $this->settings->get('blog_enabled', '1'),
            'shop_enabled'        => $this->settings->get('shop_enabled', '1'),
            'geo_mode'            => $this->settings->get('analytics_geolocation', 'none'),
            'ip_mode'             => $this->settings->get('ip_privacy_mode', 'mask'),
            'list_limit'          => self::LIST_LIMIT,
            'range_days'          => $days,
            'range_query_from'    => $query['from'] ?? null,
            'range_query_to'      => $query['to']   ?? null,
        ]);
    }

    /**
     * AJAX "load more" endpoint for the dashboard lists.
     *
     * Rows are rendered through the same Twig partials the initial page uses,
     * so markup (including dark-mode classes) stays identical. The response
     * carries the rendered HTML plus the next offset and has_more flag.
     */
    public function listRows(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $list  = (string) ($query['list'] ?? '');
        if (!in_array($list, self::ALLOWED_LISTS, true)) {
            return $this->json($response, ['error' => 'unknown list'], 400);
        }

        $series = (string) ($query['series'] ?? '4xx');
        if ($list === 'status-codes' && !in_array($series, self::STATUS_SERIES, true)) {
            return $this->json($response, ['error' => 'unknown series'], 400);
        }

        $days                       = max(1, min(365, (int) ($query['days'] ?? 7)));
        $retentionDays              = max(1, (int) ($this->settings->get('analytics_retention_days', '90') ?? 90));
        $range                      = $this->resolveRange($query, $retentionDays);
        $limit                      = max(10, min(self::MAX_LIST_LIMIT, (int) ($query['limit'] ?? self::LIST_LIMIT)));
        $offset                     = max(0, (int) ($query['offset'] ?? 0));
        $excludePaths               = $this->buildExcludedPaths();

        $type = (string) ($query['type'] ?? '');
        if ($list === 'top-cpt' && !$this->isCustomTypeSlug($type)) {
            return $this->json($response, ['error' => 'unknown content type'], 400);
        }

        $dir        = $this->sortDir($query);
        $sortBy     = (string) ($query['sort'] ?? 'views');
        $sortBy     = in_array($sortBy, self::ALLOWED_SORTS, true) ? $sortBy : 'views';
        $utmFilters = $this->parseUtmFilters($query);

        if ($list === 'status-codes') {
            $view    = 'status';
            $mode    = null;
            $rows    = $this->pageviews->statusCodeSeriesRows($series, $days, $range, $limit, $offset, $sortBy, $dir);
            $counts  = $this->pageviews->statusCodeCounts($days, $range);
            $hasMore = $offset + count($rows) < ($counts[$series] ?? 0);
            $rows    = array_slice($rows, 0, $limit);
        } elseif (str_starts_with($list, 'utm-')) {
            $dimension = substr($list, 4);
            if (!in_array($dimension, self::UTM_PANELS, true)) {
                return $this->json($response, ['error' => 'unknown list'], 400);
            }

            $page    = $this->pageviews->utmList($dimension, $days, $range, $limit, $offset, $utmFilters, $dir);
            $view    = 'utm';
            $mode    = $dimension;
            $rows    = $this->utmRows($page, $dimension, $utmFilters);
            $hasMore = $page['has_more'];
        } else {
            [$view, $mode, $rawRows] = match ($list) {
                'top-referrers' => ['referrer', null, $this->pageviews->topReferrers($limit, $days, $range, $offset, $dir)],
                'countries'     => ['geo', 'country', $this->pageviews->countryStats($days, $range, $limit, $offset, $dir)],
                'cities'        => ['geo', 'city', $this->pageviews->cityStats($days, $range, $limit, $offset, $dir)],
                'languages'     => ['geo', 'language', $this->pageviews->languageStats($days, $range, $limit, $offset, $dir)],
                'top-pages'     => ['page', 'duration', $this->pageviews->topPages($limit, $days, $excludePaths, $range, $offset, $sortBy, $dir)],
                'entry-pages'   => ['page', 'duration', $this->pageviews->entryPages($limit, $days, $excludePaths, $range, $offset, $sortBy, $dir)],
                'exit-pages'    => ['page', 'session', $this->pageviews->exitPages($limit, $days, $excludePaths, $range, $offset, $sortBy, $dir)],
                'top-posts'     => ['content', 'post', $this->topContentRows('post', $limit, $days, $range, $offset, '', $sortBy, $dir)],
                'top-products'  => ['content', 'product', $this->topContentRows('product', $limit, $days, $range, $offset, '', $sortBy, $dir)],
                'top-cpt'       => ['content', 'custom', $this->topContentRows('custom', $limit, $days, $range, $offset, $type, $sortBy, $dir)],
                default         => ['referrer', null, []],
            };

            $hasMore = count($rawRows) > $limit;
            $rows    = array_slice($rawRows, 0, $limit);
        }

        $html = '';
        foreach ($rows as $row) {
            $html .= $this->twig->fetch('admin/analytics/rows/' . $view . '.twig', [
                'row'  => $row,
                'mode' => $mode,
            ]);
        }

        return $this->json($response, [
            'html'      => $html,
            'more_html' => $hasMore
                ? $this->twig->fetch('admin/analytics/rows/more_sentinel.twig', [
                    'list'   => $list,
                    'offset' => $offset + count($rows),
                    'show'   => true,
                    'series' => $list === 'status-codes' ? $series : null,
                    'type'   => $list === 'top-cpt' ? $type : null,
                ])
                : '',
            'count'     => count($rows),
            'offset'    => $offset + count($rows),
            'has_more'  => $hasMore,
        ]);
    }

    /**
     * Re-renders the whole UTM card body for the active facet set, so clicking
     * a facet or flipping the sort never reloads the page. Returns the HTML
     * plus the applied filters/direction the client keeps in state.
     */
    public function utmPanel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($this->settings->get('analytics_enabled', '1') ?? '1') !== '1') {
            return $this->json($response, ['error' => 'analytics disabled'], 404);
        }

        $query         = $request->getQueryParams();
        $days          = max(1, min(365, (int) ($query['days'] ?? 7)));
        $retentionDays = max(1, (int) ($this->settings->get('analytics_retention_days', '90') ?? 90));
        $range         = $this->resolveRange($query, $retentionDays);

        $filters = $this->parseUtmFilters($query);
        $dir     = $this->sortDir($query);
        $stats   = $this->pageviews->utmStats($days, $range, self::LIST_LIMIT, $filters, $dir);

        return $this->json($response, [
            'html'         => $this->twig->fetch('admin/analytics/rows/utm_body.twig', [
                'utm_panels'  => $this->utmPanels($stats, $filters),
                'utm_filters' => $filters,
                'utm_dir'     => $dir,
            ]),
            'filters_html' => $this->twig->fetch('admin/analytics/rows/utm_filters.twig', [
                'utm_filters' => $filters,
            ]),
            'filters'      => $filters,
            'dir'          => $dir,
        ]);
    }

    /** Whitelisted sort direction from `?dir=`. */
    private function sortDir(array $query): string
    {
        return ((string) ($query['dir'] ?? '')) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Active UTM facet filters read from the query string. `destination` is
     * deliberately not a facet (those rows link to the page instead). The
     * reserved token `__not_set__` selects the NULL bucket, which the
     * repository receives as an empty string.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private function parseUtmFilters(array $query): array
    {
        $filters = [];
        foreach (self::UTM_PANELS as $key) {
            if ($key === 'destination' || !isset($query[$key]) || !is_string($query[$key])) {
                continue;
            }

            $value         = substr($query[$key], 0, 255);
            $filters[$key] = $value === self::UTM_NOT_SET ? '' : $value;
        }

        return $filters;
    }

    /**
     * Maps one repository UTM page into render rows. `wire` is the value the
     * facet links send back (`__not_set__` for the NULL bucket) while `label`
     * stays null there so Twig can translate "(not set)".
     *
     * @param array{rows: list<array{value: ?string, views: int}>} $page
     * @param array<string, string> $filters
     * @return list<array{wire: string, label: ?string, views: int, selected: bool}>
     */
    private function utmRows(array $page, string $dimension, array $filters): array
    {
        $selectedWire = $this->selectedWire($filters[$dimension] ?? null);

        return array_map(static function (array $row) use ($selectedWire): array {
            $wire = $row['value'] ?? self::UTM_NOT_SET;

            return [
                'wire'     => $wire,
                'label'    => $row['value'],
                'views'    => (int) $row['views'],
                'selected' => $selectedWire !== null && $wire === $selectedWire,
            ];
        }, $page['rows']);
    }

    /** The wire token selected by a filter value (null = facet not filtered). */
    private function selectedWire(?string $filterValue): ?string
    {
        if ($filterValue === null) {
            return null;
        }

        return $filterValue === '' ? self::UTM_NOT_SET : $filterValue;
    }

    /**
     * UTM panels for the card, filtered and with their selected row marked.
     * Empty non-core panels (term / content) are dropped so a site that never
     * uses them keeps the familiar four-panel card.
     *
     * @param array{dimensions: array<string, list<array{value: ?string, views: int}>>, has_more: array<string, bool>} $stats
     * @param array<string, string> $filters
     * @return list<array{key: string, rows: list<array{wire: string, label: ?string, views: int, selected: bool}>, has_more: bool}>
     */
    private function utmPanels(array $stats, array $filters): array
    {
        $panels = [];
        foreach (self::UTM_PANELS as $key) {
            $rows = $stats['dimensions'][$key] ?? [];
            if (!in_array($key, self::UTM_CORE_PANELS, true) && $rows === [] && !isset($filters[$key])) {
                continue;
            }

            $panels[] = [
                'key'      => $key,
                'rows'     => $this->utmRows(['rows' => $rows], $key, $filters),
                'has_more' => (bool) ($stats['has_more'][$key] ?? false),
            ];
        }

        return $panels;
    }

    /**
     * Paths excluded from the "Top Pages" and "Entry/Exit pages" reports:
     * internal sections (cart, checkout, login…) plus every automatic redirect
     * source (URL prefix or slug changes). Manual content aliases are
     * editorial intent and stay countable.
     *
     * @return list<string>
     */
    private function buildExcludedPaths(): array
    {
        $excludePaths = [];

        $blogPrefix = $this->settings->get('routing_blog_prefix', '/blog');
        if ($blogPrefix) {
            $excludePaths[] = $blogPrefix;
        }

        $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
        if ($shopPrefix) {
            $excludePaths[] = $shopPrefix;
        }

        $catPrefix = $this->settings->get('routing_category_prefix', '/category');
        if ($catPrefix) {
            $excludePaths[] = $catPrefix;
        }

        $tagPrefix = $this->settings->get('routing_tag_prefix', '/tag');
        if ($tagPrefix) {
            $excludePaths[] = $tagPrefix;
        }

        if ($this->settings->get('custom_content_types_enabled', '0') === '1') {
            $excludePaths[] = '/c';
            foreach ($this->contentTypes->findActiveCustom() as $ct) {
                $routePrefix = $ct->getRoutePrefix();
                if ($routePrefix) {
                    $excludePaths[] = $routePrefix;
                }
            }
        }

        $excludePaths[]   = '/cart';
        $excludePaths[]   = '/checkout';
        $excludePaths[]   = '/register';
        $membershipPrefix = $this->settings->get('routing_membership_prefix', '/membership');
        if ($membershipPrefix) {
            $excludePaths[] = $membershipPrefix;
        }
        $excludePaths[] = '/login';
        $excludePaths[] = '/logout';

        $redirectRows = $this->db->fetchAllAssociative(
            'SELECT old_path FROM ' . VL_DB_PREFIX . 'redirects WHERE is_manual = FALSE'
        );
        foreach ($redirectRows as $row) {
            $excludePaths[] = (string) $row['old_path'];
        }

        return $excludePaths;
    }

    /**
     * Top published posts (or products, or one custom content type) by
     * view_count with an optional offset. Returns up to $limit + 1 rows so
     * callers can detect a further page.
     *
     * `duration` ordering is only meaningful for posts (where the column is
     * shown) and is resolved through the pageview-derived average, see
     * topPostsByDuration().
     *
     * @return list<array<string, mixed>>
     */
    private function topContentRows(string $kind, int $limit, int $days, ?DateRange $range, int $offset = 0, string $customType = '', string $sortBy = 'views', string $dir = 'desc'): array
    {
        if ($kind === 'post' && $sortBy === 'duration') {
            return $this->topPostsByDuration($limit, $days, $range, $offset, $dir);
        }

        $order = $dir === 'asc' ? 'ASC' : 'DESC';

        if ($kind === 'product') {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, name AS title, slug, view_count FROM ' . VL_DB_PREFIX . 'products'
                . " WHERE status = 'published'"
                . ' ORDER BY view_count ' . $order . ', id ASC LIMIT ? OFFSET ?',
                [$limit + 1, $offset],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );
        } else {
            $where  = $kind === 'custom'
                ? 'content_type = ? AND status = \'published\''
                : "content_type = 'post' AND status = 'published'";
            $params = $kind === 'custom' ? [$customType] : [];

            $rows = $this->db->fetchAllAssociative(
                'SELECT id, title, slug, view_count FROM ' . VL_DB_PREFIX . 'posts'
                . ' WHERE ' . $where
                . ' ORDER BY view_count ' . $order . ', id ASC LIMIT ? OFFSET ?',
                [...$params, $limit + 1, $offset],
                [...array_fill(0, count($params), ParameterType::STRING), ParameterType::INTEGER, ParameterType::INTEGER],
            );
        }

        if ($kind !== 'post') {
            return $rows;
        }

        $durations = $this->pageviews->avgDurationByPath($days, $range);
        $prefix    = $this->blogPrefix();

        foreach ($rows as &$row) {
            $row['avg_duration'] = $durations[$prefix . '/' . $row['slug']] ?? 0.0;
        }
        unset($row);

        return $rows;
    }

    /**
     * Duration ranking for posts. The average dwell time is derived from
     * pageviews instead of being stored on the post, so the whole published
     * set is loaded and sorted in PHP — an explicit, occasional user action —
     * which keeps the ordering correct across pagination.
     *
     * @return list<array<string, mixed>>
     */
    private function topPostsByDuration(int $limit, int $days, ?DateRange $range, int $offset, string $dir): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, title, slug, view_count FROM ' . VL_DB_PREFIX . 'posts'
            . " WHERE content_type = 'post' AND status = 'published'"
        );

        $durations = $this->pageviews->avgDurationByPath($days, $range);
        $prefix    = $this->blogPrefix();

        foreach ($rows as &$row) {
            $row['avg_duration'] = $durations[$prefix . '/' . $row['slug']] ?? 0.0;
        }
        unset($row);

        $factor = $dir === 'asc' ? 1 : -1;
        usort($rows, static function (array $a, array $b) use ($factor): int {
            $cmp = ($a['avg_duration'] <=> $b['avg_duration']) * $factor;

            return $cmp !== 0 ? $cmp : strcmp((string) $a['title'], (string) $b['title']);
        });

        return array_slice($rows, $offset, $limit + 1);
    }

    /** Normalized blog route prefix (`/blog`) used to build post page paths. */
    private function blogPrefix(): string
    {
        return rtrim('/' . ltrim((string) $this->settings->get('routing_blog_prefix', '/blog'), '/'), '/');
    }

    /**
     * Cuts the repository sentinel row so the dashboard renders exactly
     * self::LIST_LIMIT rows per list.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function visible(array $rows): array
    {
        return array_slice($rows, 0, self::LIST_LIMIT);
    }

    /**
     * Whether the given slug is an active custom content type.
     */
    private function isCustomTypeSlug(string $slug): bool
    {
        foreach ($this->contentTypes->findActiveCustom() as $ct) {
            if ((string) $ct->getSlug() === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Builds a custom calendar-day range from ?from=&to= (Y-m-d).
     * Returns null when absent/invalid so callers fall back to $days.
     * Bounds are clamped: to <= today, from >= today - retention.
     */
    private function resolveRange(array $query, int $retentionDays): ?DateRange
    {
        if (!isset($query['from']) || !isset($query['to'])) {
            return null;
        }

        $from = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $query['from']);
        $to   = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $query['to']);
        if ($from === false || $to === false) {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        $min   = $today->modify("-{$retentionDays} days");

        if ($to > $today) {
            $to = $today;
        }
        if ($from < $min) {
            $from = $min;
        }
        if ($from > $to) {
            return null;
        }

        try {
            return new DateRange($from, $to);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
