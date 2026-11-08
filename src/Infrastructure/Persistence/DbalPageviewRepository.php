<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Analytics\Entity\Pageview;
use ValeApp\Domain\Analytics\Repository\PageviewRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\DateRange;

final class DbalPageviewRepository implements PageviewRepositoryInterface
{
    /** Inclusive HTTP status-code bounds of the dashboard's status-code series. */
    private const SERIES_BOUNDS = [
        '3xx' => [300, 399],
        '4xx' => [400, 499],
        '5xx' => [500, 599],
    ];

    /**
     * UTM dashboard dimensions mapped to their pageview column. The order is
     * the order the panels are rendered in. `destination` uses the query-less
     * path so landing pages group correctly.
     */
    private const UTM_COLUMNS = [
        'source'      => 'utm_source',
        'medium'      => 'utm_medium',
        'campaign'    => 'utm_campaign',
        'term'        => 'utm_term',
        'content'     => 'utm_content',
        'destination' => 'page_path',
    ];

    /** A pageview belongs to the UTM report when at least one utm_* column is set. */
    private const UTM_PRESENT = '(utm_source IS NOT NULL OR utm_medium IS NOT NULL'
        . ' OR utm_campaign IS NOT NULL OR utm_term IS NOT NULL OR utm_content IS NOT NULL)';


    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Builds the time predicate + bound params for analytics queries.
     *
     * A custom range uses calendar days (`>= from AND < toExclusive`);
     * the legacy $days mode keeps the rolling 24-hour window.
     *
     * @return array{0: string, 1: list<string>, 2: list<ParameterType>}
     */
    private function timeBounds(int $days, ?DateRange $range): array
    {
        if ($range !== null) {
            return [
                'created_at >= ? AND created_at < ?',
                [$range->fromSql(), $range->toSql()],
                [ParameterType::STRING, ParameterType::STRING],
            ];
        }

        $cutoff = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
        return ['created_at >= ?', [$cutoff], [ParameterType::STRING]];
    }

    public function save(Pageview $pageview): Pageview
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->insert(VL_DB_PREFIX . 'pageviews', [
            'page_url'        => $pageview->getPageUrl(),
            'page_title'      => $pageview->getPageTitle(),
            'referrer'        => $pageview->getReferrer(),
            'referrer_domain' => $pageview->getReferrerDomain(),
            'country'         => $pageview->getCountry(),
            'city'            => $pageview->getCity(),
            'language'        => $pageview->getLanguage(),
            'browser'         => $pageview->getBrowser(),
            'device_type'     => $pageview->getDeviceType(),
            'visitor_id'      => $pageview->getVisitorId(),
            'status_code'     => $pageview->getStatusCode(),
            'utm_source'      => $pageview->getUtmSource(),
            'utm_medium'      => $pageview->getUtmMedium(),
            'utm_campaign'    => $pageview->getUtmCampaign(),
            'utm_term'        => $pageview->getUtmTerm(),
            'utm_content'     => $pageview->getUtmContent(),
            'page_path'       => $pageview->getPagePath(),
            'created_at'      => $now,
        ]);

        $pageview->setId((int) $this->db->lastInsertId());
        $pageview->setCreatedAt(new \DateTimeImmutable($now));

        return $pageview;
    }

    public function countToday(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE SUBSTR(created_at, 1, 10) = ?',
            [(new \DateTimeImmutable())->format('Y-m-d')],
            [ParameterType::STRING]
        );
    }

    public function countUniqueToday(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT visitor_id) FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE SUBSTR(created_at, 1, 10) = ?',
            [(new \DateTimeImmutable())->format('Y-m-d')],
            [ParameterType::STRING]
        );
    }

    public function countOnline(int $minutes = 15): int
    {
        $cutoff = (new \DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s');
        return (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT visitor_id) FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE created_at >= ?',
            [$cutoff],
            [ParameterType::STRING],
        );
    }

    public function topPages(int $limit = 10, int $days = 7, array $excludePaths = [], ?DateRange $range = null, int $offset = 0, string $sortBy = 'views', string $dir = 'desc'): array
    {
        $pageStats = $this->collectStats($days, $range);

        $result = [];
        foreach ($pageStats as $cleanUrl => $data) {
            if ($this->isExcludedPath($cleanUrl, $excludePaths)) {
                continue;
            }

            $result[] = $data;
        }

        $this->sortRows($result, $sortBy === 'duration' ? 'avg_duration' : 'views', $dir, 'page_url');

        return array_slice($result, $offset, $limit + 1);
    }

    /**
     * Sorts an in-memory row list by a numeric column with a deterministic
     * string tie-breaker, so offset pagination cannot repeat or skip rows.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function sortRows(array &$rows, string $key, string $dir, string $tieBreaker): void
    {
        $factor = $dir === 'asc' ? 1 : -1;

        usort($rows, static function (array $a, array $b) use ($key, $factor, $tieBreaker): int {
            $cmp = ($a[$key] <=> $b[$key]) * $factor;

            return $cmp !== 0 ? $cmp : strcmp((string) $a[$tieBreaker], (string) $b[$tieBreaker]);
        });
    }

    /**
     * Exact or prefix match of a clean URL against the excluded path list.
     *
     * @param list<string> $excludePaths
     */
    private function isExcludedPath(string $cleanUrl, array $excludePaths): bool
    {
        foreach ($excludePaths as $p) {
            $p = '/' . ltrim((string) $p, '/');
            if ($cleanUrl === $p || str_starts_with($cleanUrl, $p . '/')) {
                return true;
            }
        }

        return false;
    }

    public function avgDurationByPath(int $days = 7, ?DateRange $range = null): array
    {
        $result = [];
        foreach ($this->collectStats($days, $range) as $cleanUrl => $data) {
            $result[$cleanUrl] = $data['avg_duration'];
        }

        return $result;
    }

    /**
     * Aggregate pageviews into per-URL stats with average dwell time
     * (time between consecutive pageviews of the same visitor, capped at 30 min).
     *
     * @return array<string, array{page_url: string, page_title: ?string, views: int, avg_duration: float}>
     */
    private function collectStats(int $days, ?DateRange $range = null): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT visitor_id, page_url, page_title, created_at FROM ' . VL_DB_PREFIX . 'pageviews WHERE ' . $timeWhere . ' ORDER BY visitor_id ASC, created_at ASC',
            $timeParams,
            $timeTypes
        );

        $pageStats      = [];
        $currentVisitor = null;
        $prevTime       = null;
        $prevCleanUrl   = null;

        foreach ($rows as $row) {
            $visitorId = $row['visitor_id'];
            $time      = strtotime($row['created_at']);
            $rawUrl    = $row['page_url'];
            $cleanUrl  = strtok($rawUrl, '?');
            $title     = $row['page_title'] ?? $cleanUrl;

            if (!isset($pageStats[$cleanUrl])) {
                $pageStats[$cleanUrl] = [
                    'page_url'   => $cleanUrl,
                    'page_title' => $title,
                    'views'      => 0,
                    'durations'  => [],
                ];
            }
            $pageStats[$cleanUrl]['views']++;
            if (empty($pageStats[$cleanUrl]['page_title']) && !empty($title)) {
                $pageStats[$cleanUrl]['page_title'] = $title;
            }

            if ($currentVisitor === $visitorId && $prevTime !== null && ($time - $prevTime) <= 1800) {
                $duration = $time - $prevTime;
                if (isset($pageStats[$prevCleanUrl])) {
                    $pageStats[$prevCleanUrl]['durations'][] = $duration;
                }
            }

            $currentVisitor = $visitorId;
            $prevTime       = $time;
            $prevCleanUrl   = $cleanUrl;
        }

        $result = [];
        foreach ($pageStats as $cleanUrl => $data) {
            $avg = 0.0;
            if (!empty($data['durations'])) {
                $avg = (float) (array_sum($data['durations']) / count($data['durations']));
            }

            $result[$cleanUrl] = [
                'page_url'     => $cleanUrl,
                'page_title'   => $data['page_title'],
                'views'        => $data['views'],
                'avg_duration' => $avg,
            ];
        }

        return $result;
    }

    public function topReferrers(int $limit = 10, int $days = 7, ?DateRange $range = null, int $offset = 0, string $dir = 'desc'): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT referrer_domain, COUNT(*) AS views FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE ' . $timeWhere
            . ' AND referrer_domain IS NOT NULL'
            . ' GROUP BY referrer_domain ORDER BY views ' . $this->orderDirection($dir) . ', referrer_domain ASC LIMIT ? OFFSET ?',
            [...$timeParams, $limit + 1, $offset],
            [...$timeTypes, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        return array_map(static fn (array $row) => [
            'referrer_domain' => $row['referrer_domain'],
            'views'           => (int) $row['views'],
        ], $rows);
    }

    /** Whitelisted SQL sort direction (never interpolate raw input). */
    private function orderDirection(string $dir): string
    {
        return $dir === 'asc' ? 'ASC' : 'DESC';
    }

    public function browserStats(int $days = 7, ?DateRange $range = null): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT browser, COUNT(*) AS views FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE ' . $timeWhere
            . ' AND browser IS NOT NULL'
            . ' GROUP BY browser ORDER BY views DESC',
            $timeParams,
            $timeTypes,
        );
        return array_map(static fn (array $row) => [
            'browser' => $row['browser'],
            'views'   => (int) $row['views'],
        ], $rows);
    }

    public function deviceStats(int $days = 7, ?DateRange $range = null): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT device_type, COUNT(*) AS views FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE ' . $timeWhere
            . ' AND device_type IS NOT NULL'
            . ' GROUP BY device_type ORDER BY views DESC',
            $timeParams,
            $timeTypes,
        );
        return array_map(static fn (array $row) => [
            'device_type' => $row['device_type'],
            'views'       => (int) $row['views'],
        ], $rows);
    }

    public function countryStats(int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0, string $dir = 'desc'): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT country, COUNT(*) AS views FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE ' . $timeWhere
            . ' AND country IS NOT NULL'
            . ' GROUP BY country ORDER BY views ' . $this->orderDirection($dir) . ', country ASC LIMIT ? OFFSET ?',
            [...$timeParams, $limit + 1, $offset],
            [...$timeTypes, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        return array_map(static fn (array $row) => [
            'country' => $row['country'],
            'views'   => (int) $row['views'],
        ], $rows);
    }

    public function cityStats(int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0, string $dir = 'desc'): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT city, country, COUNT(*) AS views FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE ' . $timeWhere
            . ' AND city IS NOT NULL'
            . ' GROUP BY city, country ORDER BY views ' . $this->orderDirection($dir) . ', city ASC, country ASC LIMIT ? OFFSET ?',
            [...$timeParams, $limit + 1, $offset],
            [...$timeTypes, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        return array_map(static fn (array $row) => [
            'city'    => $row['city'],
            'country' => $row['country'],
            'views'   => (int) $row['views'],
        ], $rows);
    }

    public function languageStats(int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0, string $dir = 'desc'): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT language, COUNT(*) AS views FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE ' . $timeWhere
            . ' AND language IS NOT NULL'
            . ' GROUP BY language ORDER BY views ' . $this->orderDirection($dir) . ', language ASC LIMIT ? OFFSET ?',
            [...$timeParams, $limit + 1, $offset],
            [...$timeTypes, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        return array_map(static fn (array $row) => [
            'language' => $row['language'],
            'views'    => (int) $row['views'],
        ], $rows);
    }

    public function dailyStats(int $days = 30, ?DateRange $range = null): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT SUBSTR(created_at, 1, 10) AS date, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS unique_visitors'
            . ' FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE ' . $timeWhere
            . ' GROUP BY SUBSTR(created_at, 1, 10) ORDER BY date ASC',
            $timeParams,
            $timeTypes,
        );
        return array_map(static fn (array $row) => [
            'date'   => $row['date'],
            'views'  => (int) $row['views'],
            'unique' => (int) $row['unique_visitors'],
        ], $rows);
    }

    public function countDays(int $days = 7, ?DateRange $range = null): int
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE ' . $timeWhere,
            $timeParams,
            $timeTypes,
        );
    }

    public function countReferrers(int $days = 7, ?DateRange $range = null): int
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        return (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT referrer_domain) FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE ' . $timeWhere
            . ' AND referrer_domain IS NOT NULL',
            $timeParams,
            $timeTypes,
        );
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM ' . VL_DB_PREFIX . 'pageviews WHERE created_at < ?',
            [$before->format('Y-m-d H:i:s')],
            [ParameterType::STRING],
        );
    }

    public function getSessionMetrics(int $days = 7, ?DateRange $range = null): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT visitor_id, created_at FROM ' . VL_DB_PREFIX . 'pageviews WHERE ' . $timeWhere . ' ORDER BY visitor_id ASC, created_at ASC',
            $timeParams,
            $timeTypes
        );

        $sessions       = [];
        $currentVisitor = null;
        $sessionStart   = null;
        $sessionLast    = null;
        $sessionPages   = 0;

        foreach ($rows as $row) {
            $visitorId = $row['visitor_id'];
            $time      = strtotime($row['created_at']);
            if ($time === false) {
                continue;
            }

            if ($currentVisitor !== $visitorId || ($time - $sessionLast) > 1800) {
                if ($currentVisitor !== null) {
                    $sessions[] = [
                        'duration' => $sessionLast - $sessionStart,
                        'pages'    => $sessionPages,
                    ];
                }
                $currentVisitor = $visitorId;
                $sessionStart   = $time;
                $sessionLast    = $time;
                $sessionPages   = 1;
            } else {
                $sessionLast  = $time;
                $sessionPages++;
            }
        }

        if ($currentVisitor !== null) {
            $sessions[] = [
                'duration' => $sessionLast - $sessionStart,
                'pages'    => $sessionPages,
            ];
        }

        $totalSessions = count($sessions);
        $totalDuration = 0;
        $totalPages    = 0;
        $bounces       = 0;

        foreach ($sessions as $s) {
            $totalDuration += $s['duration'];
            $totalPages    += $s['pages'];
            if ($s['pages'] === 1) {
                $bounces++;
            }
        }

        return [
            'avg_duration' => $totalSessions > 0 ? (float) ($totalDuration / $totalSessions) : 0.0,
            'bounce_rate'  => $totalSessions > 0 ? (float) (($bounces / $totalSessions) * 100) : 0.0,
            'avg_pages'    => $totalSessions > 0 ? (float) ($totalPages / $totalSessions) : 0.0,
        ];
    }

    public function entryPages(int $limit = 10, int $days = 7, array $excludePaths = [], ?DateRange $range = null, int $offset = 0, string $sortBy = 'views', string $dir = 'desc'): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT visitor_id, page_url, page_title, created_at FROM ' . VL_DB_PREFIX . 'pageviews WHERE ' . $timeWhere . ' ORDER BY visitor_id ASC, created_at ASC',
            $timeParams,
            $timeTypes
        );

        // Each session's entry page keeps the dwell time until the *second*
        // pageview (capped at 30 min, like Top Pages). Bounce sessions have no
        // second pageview, so their duration stays null and is excluded from
        // the average instead of being counted as zero.
        $entries        = [];
        $currentVisitor = null;
        $firstRow       = null;
        $firstTime      = null;
        $entryDuration  = null;
        $sessionLast    = null;

        foreach ($rows as $row) {
            $visitorId = $row['visitor_id'];
            $time      = strtotime((string) $row['created_at']);
            if ($time === false) {
                continue;
            }

            $newSession = $currentVisitor !== $visitorId || ($sessionLast !== null && ($time - $sessionLast) > 1800);
            if ($newSession) {
                if ($firstRow !== null) {
                    $entries[] = ['row' => $firstRow, 'duration' => $entryDuration];
                }
                $currentVisitor = $visitorId;
                $firstRow       = $row;
                $firstTime      = $time;
                $entryDuration  = null;
                $sessionLast    = $time;
                continue;
            }

            if ($entryDuration === null && $firstTime !== null && ($time - $firstTime) <= 1800) {
                $entryDuration = $time - $firstTime;
            }
            $sessionLast = $time;
        }

        if ($firstRow !== null) {
            $entries[] = ['row' => $firstRow, 'duration' => $entryDuration];
        }

        $stats     = [];
        $durations = [];
        foreach ($entries as $entry) {
            $cleanUrl = strtok((string) $entry['row']['page_url'], '?');
            if ($cleanUrl === false || $this->isExcludedPath($cleanUrl, $excludePaths)) {
                continue;
            }
            $title = $entry['row']['page_title'] ?? $cleanUrl;
            if (!isset($stats[$cleanUrl])) {
                $stats[$cleanUrl]     = [
                    'page_url'     => $cleanUrl,
                    'page_title'   => $title,
                    'views'        => 0,
                    'avg_duration' => 0.0,
                ];
                $durations[$cleanUrl] = [];
            }
            $stats[$cleanUrl]['views']++;
            if (empty($stats[$cleanUrl]['page_title']) && !empty($title)) {
                $stats[$cleanUrl]['page_title'] = $title;
            }
            if ($entry['duration'] !== null) {
                $durations[$cleanUrl][] = $entry['duration'];
            }
        }

        foreach ($durations as $cleanUrl => $values) {
            if ($values !== []) {
                $stats[$cleanUrl]['avg_duration'] = (float) (array_sum($values) / count($values));
            }
        }

        $this->sortRows($stats, $sortBy === 'duration' ? 'avg_duration' : 'views', $dir, 'page_url');
        return array_slice($stats, $offset, $limit + 1);
    }

    public function exitPages(int $limit = 10, int $days = 7, array $excludePaths = [], ?DateRange $range = null, int $offset = 0, string $sortBy = 'views', string $dir = 'desc'): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);
        $rows                                 = $this->db->fetchAllAssociative(
            'SELECT visitor_id, page_url, page_title, created_at FROM ' . VL_DB_PREFIX . 'pageviews WHERE ' . $timeWhere . ' ORDER BY visitor_id ASC, created_at ASC',
            $timeParams,
            $timeTypes
        );

        // An exit page has no following pageview, so "time on page" is not
        // measurable. The meaningful, measurable proxy is the full session
        // duration of the sessions that ended there — surfaced as
        // "Avg. Session Duration" rather than a misleading time-on-page.
        $exits          = [];
        $currentVisitor = null;
        $sessionStart   = null;
        $sessionLast    = null;
        $lastPageview   = null;

        foreach ($rows as $row) {
            $visitorId = $row['visitor_id'];
            $time      = strtotime((string) $row['created_at']);
            if ($time === false) {
                continue;
            }

            if ($currentVisitor !== $visitorId || ($time - $sessionLast) > 1800) {
                if ($lastPageview !== null) {
                    $exits[] = ['row' => $lastPageview, 'duration' => $sessionLast - $sessionStart];
                }
                $currentVisitor = $visitorId;
                $sessionStart   = $time;
                $sessionLast    = $time;
                $lastPageview   = $row;
            } else {
                $sessionLast  = $time;
                $lastPageview = $row;
            }
        }

        if ($lastPageview !== null) {
            $exits[] = ['row' => $lastPageview, 'duration' => $sessionLast - $sessionStart];
        }

        $stats     = [];
        $durations = [];
        foreach ($exits as $exit) {
            $cleanUrl = strtok((string) $exit['row']['page_url'], '?');
            if ($cleanUrl === false || $this->isExcludedPath($cleanUrl, $excludePaths)) {
                continue;
            }
            $title = $exit['row']['page_title'] ?? $cleanUrl;
            if (!isset($stats[$cleanUrl])) {
                $stats[$cleanUrl]     = [
                    'page_url'             => $cleanUrl,
                    'page_title'           => $title,
                    'views'                => 0,
                    'avg_session_duration' => 0.0,
                ];
                $durations[$cleanUrl] = [];
            }
            $stats[$cleanUrl]['views']++;
            if (empty($stats[$cleanUrl]['page_title']) && !empty($title)) {
                $stats[$cleanUrl]['page_title'] = $title;
            }
            $durations[$cleanUrl][] = $exit['duration'];
        }

        foreach ($durations as $cleanUrl => $values) {
            if ($values !== []) {
                $stats[$cleanUrl]['avg_session_duration'] = (float) (array_sum($values) / count($values));
            }
        }

        $this->sortRows($stats, $sortBy === 'session_duration' ? 'avg_session_duration' : 'views', $dir, 'page_url');
        return array_slice($stats, $offset, $limit + 1);
    }

    /**
     * Every UTM panel for the dashboard, in render order.
     *
     * @param array<string, string> $filters dimension key => value ('' means "not set")
     * @return array{dimensions: array<string, list<array{value: ?string, views: int}>>, has_more: array<string, bool>, filters: array<string, string>}
     */
    public function utmStats(int $days = 7, ?DateRange $range = null, int $limit = 100, array $filters = [], string $dir = 'desc'): array
    {
        $dimensions = [];
        $hasMore    = [];

        foreach (array_keys(self::UTM_COLUMNS) as $key) {
            $page                = $this->utmDimension($key, $days, $range, $limit, 0, $filters, $dir);
            $dimensions[$key]    = $page['rows'];
            $hasMore[$key]       = $page['has_more'];
        }

        return [
            'dimensions' => $dimensions,
            'has_more'   => $hasMore,
            'filters'    => $filters,
        ];
    }

    /**
     * One UTM panel page (`source` / `medium` / `campaign` / `term` / `content`
     * / `destination`). Unknown dimensions return an empty page.
     *
     * @param array<string, string> $filters dimension key => value ('' means "not set")
     * @return array{rows: list<array{value: ?string, views: int}>, total: int, has_more: bool}
     */
    public function utmList(string $kind, int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0, array $filters = [], string $dir = 'desc'): array
    {
        if (!isset(self::UTM_COLUMNS[$kind])) {
            return ['rows' => [], 'total' => 0, 'has_more' => false];
        }

        return $this->utmDimension($kind, $days, $range, $limit, $offset, $filters, $dir);
    }

    /**
     * Groups one UTM dimension by its column. Each facet is counted with every
     * *other* active facet applied (standard faceted search), so switching a
     * filter stays possible while the values of the filtered dimension are
     * still listed.
     *
     * The `views, value` ordering is deterministic — offset pagination needs a
     * stable tie-breaker or pages would repeat/skip rows with equal counts.
     *
     * @param array<string, string> $filters
     * @return array{rows: list<array{value: ?string, views: int}>, total: int, has_more: bool}
     */
    private function utmDimension(string $kind, int $days, ?DateRange $range, int $limit, int $offset, array $filters, string $dir): array
    {
        $column = self::UTM_COLUMNS[$kind];

        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);

        $where  = [$timeWhere, self::UTM_PRESENT];
        $params = $timeParams;
        $types  = $timeTypes;

        foreach ($filters as $key => $value) {
            if ($key === $kind || !isset(self::UTM_COLUMNS[$key])) {
                continue;
            }

            if ($value === '') {
                $where[] = self::UTM_COLUMNS[$key] . ' IS NULL';
            } else {
                $where[]  = self::UTM_COLUMNS[$key] . ' = ?';
                $params[] = $value;
                $types[]  = ParameterType::STRING;
            }
        }

        $order = $dir === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . $column . ' AS value, COUNT(*) AS views'
            . ' FROM ' . VL_DB_PREFIX . 'pageviews'
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY ' . $column
            . ' ORDER BY views ' . $order . ', value ASC'
            . ' LIMIT ? OFFSET ?',
            [...$params, $limit + 1, $offset],
            [...$types, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        $hasMore = count($rows) > $limit;
        $rows    = array_slice($rows, 0, $limit);

        return [
            'rows' => array_map(static fn (array $row): array => [
                'value' => $row['value'] === null ? null : (string) $row['value'],
                'views' => (int) $row['views'],
            ], $rows),
            'total'    => count($rows),
            'has_more' => $hasMore,
        ];
    }

    public function statusCodeStats(int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0): array
    {
        $grouped = [];
        foreach (array_keys(self::SERIES_BOUNDS) as $series) {
            $grouped[$series] = $this->statusCodeSeriesRows($series, $days, $range, $limit, $offset);
        }

        return $grouped;
    }

    /**
     * One status-code series page (3xx / 4xx / 5xx). Unknown series → empty list.
     *
     * @return list<array{status_code: int, page_url: string, views: int, last_seen: string}>
     */
    public function statusCodeSeriesRows(string $series, int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0, string $sortBy = 'views', string $dir = 'desc'): array
    {
        if (!isset(self::SERIES_BOUNDS[$series])) {
            return [];
        }

        [$from, $to] = self::SERIES_BOUNDS[$series];

        return $this->statusCodeRows($days, $range, $from, $to, $limit, $offset, $sortBy, $dir);
    }

    public function statusCodeCounts(int $days = 7, ?DateRange $range = null): array
    {
        $counts = [];
        foreach (self::SERIES_BOUNDS as $series => [$from, $to]) {
            $counts[$series] = $this->statusCodeSeriesCount($days, $range, $from, $to);
        }

        return $counts;
    }

    /**
     * @return list<array{status_code: int, page_url: string, views: int, last_seen: string}>
     */
    private function statusCodeRows(int $days, ?DateRange $range, int $from, int $to, int $limit, int $offset, string $sortBy = 'views', string $dir = 'desc'): array
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);

        $order   = $this->orderDirection($dir);
        $orderBy = $sortBy === 'last_seen'
            ? ' ORDER BY last_seen ' . $order . ', page_url ASC'
            : ' ORDER BY views ' . $order . ', last_seen DESC, page_url ASC';

        $sql = 'SELECT p.status_code, p.page_url, COUNT(*) AS views, MAX(p.created_at) AS last_seen'
            . ' FROM ' . VL_DB_PREFIX . 'pageviews p'
            . ' WHERE ' . $timeWhere
            . ' AND p.status_code >= ? AND p.status_code <= ?'
            . $this->automaticRedirectPredicate()
            . ' GROUP BY p.status_code, p.page_url'
            . $orderBy
            . ' LIMIT ? OFFSET ?';

        $params = [...$timeParams, $from, $to, $limit + 1, $offset];
        $types  = [...$timeTypes, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER];

        return array_map(static fn (array $row) => [
            'status_code' => (int) $row['status_code'],
            'page_url'    => (string) $row['page_url'],
            'views'       => (int) $row['views'],
            'last_seen'   => (string) $row['last_seen'],
        ], $this->db->fetchAllAssociative($sql, $params, $types));
    }

    private function statusCodeSeriesCount(int $days, ?DateRange $range, int $from, int $to): int
    {
        [$timeWhere, $timeParams, $timeTypes] = $this->timeBounds($days, $range);

        $sql = 'SELECT COUNT(*) FROM ('
            . 'SELECT 1 FROM ' . VL_DB_PREFIX . 'pageviews p'
            . ' WHERE ' . $timeWhere
            . ' AND p.status_code >= ? AND p.status_code <= ?'
            . $this->automaticRedirectPredicate()
            . ' GROUP BY p.status_code, p.page_url'
            . ') AS series_rows';

        $params = [...$timeParams, $from, $to];
        $types  = [...$timeTypes, ParameterType::INTEGER, ParameterType::INTEGER];

        return (int) $this->db->fetchOne($sql, $params, $types);
    }

    /**
     * SQL predicate that keeps system-generated redirect sources out of the
     * HTTP status reports:
     * - URLs with a trailing slash (canonicalized by TrailingSlashMiddleware,
     *   e.g. /shop/ → /shop)
     * - Automatic redirect records in vl_redirects (URL prefix or slug changes).
     *
     * Manual content aliases (is_manual = TRUE) are editorial intent and stay
     * visible.
     */
    private function automaticRedirectPredicate(): string
    {
        $concat = $this->db->getDatabasePlatform()->getConcatExpression('r.old_path', "'/%'");

        return ' AND NOT EXISTS ('
            . 'SELECT 1 FROM ' . VL_DB_PREFIX . 'redirects r'
            . ' WHERE r.is_manual = FALSE'
            . " AND (p.page_url = r.old_path OR p.page_url LIKE {$concat})"
            . ')'
            . " AND p.page_url <> '/' AND p.page_url NOT LIKE '%/'";
    }
}
