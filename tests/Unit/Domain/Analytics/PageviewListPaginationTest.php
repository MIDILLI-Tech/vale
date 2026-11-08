<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Analytics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Persistence\DbalPageviewRepository;

final class PageviewListPaginationTest extends TestCase
{
    public function testStatusCodeStatsPassSeriesBoundsLimitAndOffset(): void
    {
        $captured = [];
        $db       = $this->connectionCapturing([], $captured);

        $repo = new DbalPageviewRepository($db);
        $repo->statusCodeStats(7, null, 5, 10);
        // One query per series; params are [time cutoff, code from, code to, limit + 1, offset]
        self::assertCount(3, $captured);
        self::assertSame(300, $captured[0]['params'][1]);
        self::assertSame(399, $captured[0]['params'][2]);
        self::assertSame(400, $captured[1]['params'][1]);
        self::assertSame(499, $captured[1]['params'][2]);
        self::assertSame(500, $captured[2]['params'][1]);
        self::assertSame(599, $captured[2]['params'][2]);
        self::assertSame(6, $captured[0]['params'][3], 'the extra sentinel row lets callers detect has_more');
        self::assertSame(10, $captured[0]['params'][4]);
    }

    public function testStatusCodeSeriesRowsRejectsUnknownSeries(): void
    {
        $db = $this->connectionCapturing([]);

        $repo = new DbalPageviewRepository($db);
        self::assertSame([], $repo->statusCodeSeriesRows('2xx'));
    }

    public function testPredicateHidesTrailingSlashAndAutomaticRedirects(): void
    {
        $captured = [];
        $db       = $this->connectionCapturing([], $captured);

        $repo = new DbalPageviewRepository($db);
        $repo->statusCodeSeriesRows('4xx', 7, null, 200, 0);

        self::assertCount(1, $captured);
        self::assertStringContainsString('NOT EXISTS', $captured[0]['sql']);
        self::assertStringContainsString('is_manual = FALSE', $captured[0]['sql'], 'manual aliases stay visible');
        self::assertStringContainsString("p.page_url NOT LIKE '%/'", $captured[0]['sql']);
    }

    public function testTopPagesAppliesOffsetAndExclusions(): void
    {
        $rows = [
            ['visitor_id' => 'v1', 'page_url' => '/cart', 'page_title' => 'Cart', 'created_at' => '2026-07-02 12:00:00'],
            ['visitor_id' => 'v1', 'page_url' => '/b', 'page_title' => 'B', 'created_at' => '2026-07-02 12:01:00'],
            ['visitor_id' => 'v2', 'page_url' => '/b', 'page_title' => 'B', 'created_at' => '2026-07-02 12:02:00'],
        ];
        $db = $this->connectionCapturing($rows);

        $repo = new DbalPageviewRepository($db);

        // /cart excluded; /b has 2 views → first page of 1
        $page = $repo->topPages(1, 7, ['/cart']);
        self::assertCount(1, $page);
        self::assertSame('/b', $page[0]['page_url']);
        self::assertSame(2, $page[0]['views']);

        // Second page of 1 (offset skips the leading /b row)
        $next = $repo->topPages(1, 7, [], null, 1);
        self::assertCount(1, $next);
        self::assertSame('/cart', $next[0]['page_url']);
    }

    public function testTopPagesTieBreakKeepsPagingStable(): void
    {
        $rows = [
            ['visitor_id' => 'v1', 'page_url' => '/b', 'page_title' => 'B', 'created_at' => '2026-07-02 12:00:00'],
            ['visitor_id' => 'v1', 'page_url' => '/a', 'page_title' => 'A', 'created_at' => '2026-07-02 12:01:00'],
            ['visitor_id' => 'v1', 'page_url' => '/c', 'page_title' => 'C', 'created_at' => '2026-07-02 12:02:00'],
        ];
        $db = $this->connectionCapturing($rows);

        $repo = new DbalPageviewRepository($db);

        // Equal view counts must fall back to page_url ASC, otherwise offset
        // pages could repeat or skip rows.
        self::assertSame('/a', $repo->topPages(1, 7)[0]['page_url']);
        self::assertSame('/b', $repo->topPages(1, 7, [], null, 1)[0]['page_url']);
        self::assertSame('/c', $repo->topPages(1, 7, [], null, 2)[0]['page_url']);
    }

    public function testTopPagesCanSortByDuration(): void
    {
        $rows = [
            // v1 spends 100s on /slow, then goes to /fast after 20s
            ['visitor_id' => 'v1', 'page_url' => '/slow', 'page_title' => 'Slow', 'created_at' => '2026-07-02 12:00:00'],
            ['visitor_id' => 'v1', 'page_url' => '/fast', 'page_title' => 'Fast', 'created_at' => '2026-07-02 12:01:40'],
            ['visitor_id' => 'v2', 'page_url' => '/slow', 'page_title' => 'Slow', 'created_at' => '2026-07-02 13:00:00'],
            ['visitor_id' => 'v2', 'page_url' => '/fast', 'page_title' => 'Fast', 'created_at' => '2026-07-02 13:00:20'],
        ];
        $db = $this->connectionCapturing($rows);

        $repo = new DbalPageviewRepository($db);

        $byDuration = $repo->topPages(5, 7, [], null, 0, 'duration');
        self::assertSame('/slow', $byDuration[0]['page_url']);

        $ascending = $repo->topPages(5, 7, [], null, 0, 'duration', 'asc');
        self::assertSame('/fast', $ascending[0]['page_url']);
    }

    public function testEntryPagesRespectExcludePathsAndOffset(): void
    {
        $rows = [
            ['visitor_id' => 'v1', 'page_url' => '/home', 'page_title' => 'Home', 'created_at' => '2026-07-02 12:00:00'],
            ['visitor_id' => 'v2', 'page_url' => '/home', 'page_title' => 'Home', 'created_at' => '2026-07-02 12:05:00'],
            ['visitor_id' => 'v3', 'page_url' => '/about', 'page_title' => 'About', 'created_at' => '2026-07-02 12:10:00'],
        ];
        $db = $this->connectionCapturing($rows);

        $repo     = new DbalPageviewRepository($db);
        $excluded = $repo->entryPages(10, 7, ['/home']);
        self::assertCount(1, $excluded);
        self::assertSame('/about', $excluded[0]['page_url']);

        $paged = $repo->entryPages(1, 7, [], null, 1);
        self::assertCount(1, $paged);
        self::assertSame('/about', $paged[0]['page_url']);
    }

    public function testGroupByListsPassLimitOffsetAsParameters(): void
    {
        $captured = [];
        $db       = $this->connectionCapturing([], $captured);

        $repo = new DbalPageviewRepository($db);
        $repo->countryStats(7, null, 200, 400);

        self::assertCount(1, $captured);
        self::assertStringContainsString('LIMIT ? OFFSET ?', $captured[0]['sql']);
        // [time cutoff, limit + 1, offset]
        self::assertSame(201, $captured[0]['params'][1]);
        self::assertSame(400, $captured[0]['params'][2]);
    }

    public function testUtmListPaginatesOneDimension(): void
    {
        $captured = [];
        // The stub replays rows exactly as the SQL result set would look.
        $db = $this->connectionCapturing(
            [
                ['value' => 'spring', 'views' => 3],
                ['value' => 'summer', 'views' => 1],
            ],
            $captured,
        );

        $repo = new DbalPageviewRepository($db);

        $page = $repo->utmList('campaign', 7, null, 1, 0);
        self::assertSame([['value' => 'spring', 'views' => 3]], $page['rows']);
        self::assertTrue($page['has_more'], 'the extra sentinel row signals a further page');

        $sql = $captured[0]['sql'];
        self::assertStringContainsString('utm_campaign AS value', $sql);
        self::assertStringContainsString('GROUP BY utm_campaign', $sql);
        self::assertStringContainsString('ORDER BY views DESC, value ASC', $sql, 'ties need a stable order for pagination');

        self::assertSame([], $repo->utmList('unknown')['rows']);
    }

    public function testUtmDimensionAppliesOtherFacetsAndDirection(): void
    {
        $captured = [];
        $db       = $this->connectionCapturing([], $captured);

        $repo = new DbalPageviewRepository($db);
        $repo->utmList('source', 7, null, 10, 0, ['campaign' => 'spring', 'medium' => ''], 'asc');

        self::assertCount(1, $captured);
        $sql = $captured[0]['sql'];

        self::assertStringContainsString('utm_campaign = ?', $sql);
        self::assertStringContainsString('utm_medium IS NULL', $sql, 'an empty filter value selects the (not set) bucket');
        self::assertStringNotContainsString('utm_source = ?', $sql, 'a facet is counted with every other facet applied');
        self::assertStringContainsString('ORDER BY views ASC', $sql);
        self::assertContains('spring', $captured[0]['params']);
    }

    public function testUtmMissingDimensionsAreGroupedAsNull(): void
    {
        $db = $this->connectionCapturing([
            ['value' => null, 'views' => 4],
            ['value' => 'spring', 'views' => 2],
        ]);

        $repo = new DbalPageviewRepository($db);
        $page = $repo->utmList('campaign', 7);

        self::assertNull($page['rows'][0]['value'], 'the controller renders the null bucket as "(not set)"');
        self::assertSame('spring', $page['rows'][1]['value']);
    }

    /**
     * Connection stub that records every executed statement plus its bound
     * params and replays rows.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array{sql: string, params: list<mixed>}> $captured
     */
    private function connectionCapturing(array $rows, ?array &$captured = null): Connection
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured, $rows): array {
                $captured[] = ['sql' => $sql, 'params' => $params];
                return $rows;
            }
        );
        $db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

        return $db;
    }
}
