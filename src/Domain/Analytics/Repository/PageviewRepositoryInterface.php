<?php

declare(strict_types=1);

namespace ValeApp\Domain\Analytics\Repository;

use ValeApp\Domain\Analytics\Entity\Pageview;
use ValeApp\Domain\Shared\ValueObject\DateRange;

/**
 * Analytics read model.
 *
 * The paginated list methods (topPages, topReferrers, countryStats, cityStats,
 * languageStats, entryPages, exitPages, utmList, statusCodeStats, top posts /
 * products) fetch up to $limit + 1 rows and return them all. The extra
 * sentinel row lets callers detect “has more” without a second COUNT query:
 * `count($rows) > $limit` means a further page exists.
 */
interface PageviewRepositoryInterface
{
    public function save(Pageview $pageview): Pageview;

    public function countToday(): int;

    public function countUniqueToday(): int;

    public function countOnline(int $minutes = 15): int;

    /** @return array<array{page_url: string, page_title: ?string, views: int, avg_duration: float}> */
    public function topPages(int $limit = 10, int $days = 7, array $excludePaths = [], ?DateRange $range = null, int $offset = 0, string $sortBy = 'views', string $dir = 'desc'): array;

    /** @return array<array{referrer_domain: ?string, views: int}> */
    public function topReferrers(int $limit = 10, int $days = 7, ?DateRange $range = null, int $offset = 0, string $dir = 'desc'): array;

    /** @return array<array{browser: ?string, views: int}> */
    public function browserStats(int $days = 7, ?DateRange $range = null): array;

    /** @return array<array{device_type: ?string, views: int}> */
    public function deviceStats(int $days = 7, ?DateRange $range = null): array;

    /** @return array<array{country: ?string, views: int}> */
    public function countryStats(int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0, string $dir = 'desc'): array;

    /** @return array<array{city: ?string, country: ?string, views: int}> */
    public function cityStats(int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0, string $dir = 'desc'): array;

    /** @return array<array{language: ?string, views: int}> */
    public function languageStats(int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0, string $dir = 'desc'): array;

    /** @return array<array{date: string, views: int, unique: int}> */
    public function dailyStats(int $days = 30, ?DateRange $range = null): array;

    public function countDays(int $days = 7, ?DateRange $range = null): int;

    public function countReferrers(int $days = 7, ?DateRange $range = null): int;

    public function deleteOlderThan(\DateTimeImmutable $before): int;

    /** @return array{avg_duration: float, bounce_rate: float, avg_pages: float} */
    public function getSessionMetrics(int $days = 7, ?DateRange $range = null): array;

    /** @return array<array{page_url: string, page_title: ?string, views: int, avg_duration: float}> */
    public function entryPages(int $limit = 10, int $days = 7, array $excludePaths = [], ?DateRange $range = null, int $offset = 0, string $sortBy = 'views', string $dir = 'desc'): array;

    /** @return array<array{page_url: string, page_title: ?string, views: int, avg_session_duration: float}> */
    public function exitPages(int $limit = 10, int $days = 7, array $excludePaths = [], ?DateRange $range = null, int $offset = 0, string $sortBy = 'views', string $dir = 'desc'): array;

    /** @return array<string, float> Map of clean page path => average duration in seconds */
    public function avgDurationByPath(int $days = 7, ?DateRange $range = null): array;

    /**
     * Every UTM panel (source / medium / campaign / term / content /
     * destination) with an optional facet filter set. Filter values of `''`
     * select the "(not set)" bucket; missing keys apply no filter.
     *
     * @param array<string, string> $filters
     * @return array{dimensions: array<string, list<array{value: ?string, views: int}>>, has_more: array<string, bool>, filters: array<string, string>}
     */
    public function utmStats(int $days = 7, ?DateRange $range = null, int $limit = 100, array $filters = [], string $dir = 'desc'): array;

    /**
     * One UTM panel page.
     *
     * @param array<string, string> $filters
     * @return array{rows: list<array{value: ?string, views: int}>, total: int, has_more: bool}
     */
    public function utmList(string $kind, int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0, array $filters = [], string $dir = 'desc'): array;

    /** @return array<string, list<array{status_code: int, page_url: string, views: int, last_seen: string}>> */
    public function statusCodeStats(int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0): array;

    /**
     * @return list<array{status_code: int, page_url: string, views: int, last_seen: string}>
     */
    public function statusCodeSeriesRows(string $series, int $days = 7, ?DateRange $range = null, int $limit = 100, int $offset = 0, string $sortBy = 'views', string $dir = 'desc'): array;

    /** @return array{3xx: int, 4xx: int, 5xx: int} */
    public function statusCodeCounts(int $days = 7, ?DateRange $range = null): array;
}
