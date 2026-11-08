<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Analytics;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\ValueObject\DateRange;
use ValeApp\Infrastructure\Persistence\DbalPageviewRepository;

final class PageviewSessionTest extends TestCase
{
    public function testGetSessionMetricsCalculation(): void
    {
        $db = $this->createStub(\Doctrine\DBAL\Connection::class);

        $rows = [
            ['visitor_id' => 'v1', 'created_at' => '2026-07-02 12:00:00'],
            ['visitor_id' => 'v1', 'created_at' => '2026-07-02 12:00:50'],
            ['visitor_id' => 'v1', 'created_at' => '2026-07-02 14:00:00'],
            ['visitor_id' => 'v2', 'created_at' => '2026-07-02 12:05:00'],
            ['visitor_id' => 'v3', 'created_at' => '2026-07-02 12:10:00'],
            ['visitor_id' => 'v3', 'created_at' => '2026-07-02 12:12:00'],
            ['visitor_id' => 'v3', 'created_at' => '2026-07-02 12:15:00'],
        ];

        $db->method('fetchAllAssociative')->willReturn($rows);

        $repo    = new DbalPageviewRepository($db);
        $metrics = $repo->getSessionMetrics(7);

        self::assertSame(87.5, $metrics['avg_duration']);
        self::assertSame(50.0, $metrics['bounce_rate']);
        self::assertSame(1.75, $metrics['avg_pages']);
    }

    public function testEntryAndExitPagesCalculation(): void
    {
        $db = $this->createStub(\Doctrine\DBAL\Connection::class);

        $rows = [
            // Session 1: Entry /home, Exit /cart
            ['visitor_id' => 'v1', 'page_url' => '/home', 'page_title' => 'Home', 'created_at' => '2026-07-02 12:00:00'],
            ['visitor_id' => 'v1', 'page_url' => '/cart', 'page_title' => 'Cart', 'created_at' => '2026-07-02 12:05:00'],

            // Session 2: Entry /blog, Exit /blog (single page)
            ['visitor_id' => 'v2', 'page_url' => '/blog', 'page_title' => 'Blog', 'created_at' => '2026-07-02 12:10:00'],
        ];

        $db->method('fetchAllAssociative')->willReturn($rows);

        $repo = new DbalPageviewRepository($db);

        $entries = $repo->entryPages(10, 7);
        $exits   = $repo->exitPages(10, 7);

        // Entry count: /blog = 1, /home = 1 (ties broken by page_url ASC)
        self::assertCount(2, $entries);
        self::assertSame(['/blog', '/home'], array_column($entries, 'page_url'));
        self::assertSame(1, $entries[0]['views']);

        // The entry page keeps the dwell time until the second pageview of the
        // session: /home → /cart took 5 minutes, the single-page /blog session
        // has no measurable dwell time (bounce, excluded from the average).
        self::assertSame(300.0, $entries[1]['avg_duration']);
        self::assertSame(0.0, $entries[0]['avg_duration']);

        // Exit count: /blog = 1, /cart = 1
        self::assertCount(2, $exits);
        $exitDurations = array_column($exits, 'avg_session_duration', 'page_url');
        // Sessions ended on /cart ran 5 minutes, the /blog session 0 seconds.
        self::assertSame(300.0, $exitDurations['/cart']);
        self::assertSame(0.0, $exitDurations['/blog']);
    }

    public function testAvgDurationByPathCalculation(): void
    {
        $db = $this->createStub(\Doctrine\DBAL\Connection::class);

        // Rows as returned by ORDER BY visitor_id ASC, created_at ASC
        $rows = [
            // v1: /blog/hello → 40s → /blog/world, then a 2h gap (new session), /about → 15s → /blog/hello
            ['visitor_id' => 'v1', 'page_url' => '/blog/hello', 'page_title' => 'Hello', 'created_at' => '2026-07-02 12:00:00'],
            ['visitor_id' => 'v1', 'page_url' => '/blog/world', 'page_title' => 'World', 'created_at' => '2026-07-02 12:00:40'],
            ['visitor_id' => 'v1', 'page_url' => '/about', 'page_title' => 'About', 'created_at' => '2026-07-02 14:00:00'],
            ['visitor_id' => 'v1', 'page_url' => '/blog/hello', 'page_title' => 'Hello', 'created_at' => '2026-07-02 14:00:15'],
            // v2: /blog/hello → 80s → /home
            ['visitor_id' => 'v2', 'page_url' => '/blog/hello', 'page_title' => 'Hello', 'created_at' => '2026-07-02 12:05:00'],
            ['visitor_id' => 'v2', 'page_url' => '/home', 'page_title' => 'Home', 'created_at' => '2026-07-02 12:06:20'],
        ];

        $db->method('fetchAllAssociative')->willReturn($rows);

        $repo      = new DbalPageviewRepository($db);
        $durations = $repo->avgDurationByPath(7);

        // /blog/hello durations: [40, 80] → avg 60.0
        self::assertSame(60.0, $durations['/blog/hello']);
        // /about durations: [15]
        self::assertSame(15.0, $durations['/about']);
        // Last pageview in a session gets no dwell time
        self::assertSame(0.0, $durations['/blog/world']);
        self::assertSame(0.0, $durations['/home']);
    }

    public function testUtmStatsGroupsEachDimensionInSql(): void
    {
        $captured = [];
        $db       = $this->createStub(\Doctrine\DBAL\Connection::class);
        $db->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured[] = ['sql' => $sql, 'params' => $params];

                return match (true) {
                    str_contains($sql, 'utm_source AS value') => [
                        ['value' => 'newsletter', 'views' => 2],
                        ['value' => null, 'views' => 1],
                    ],
                    str_contains($sql, 'utm_campaign AS value') => [['value' => 'summer_sale', 'views' => 2]],
                    str_contains($sql, 'utm_medium AS value')   => [['value' => 'email', 'views' => 2]],
                    str_contains($sql, 'page_path AS value')    => [['value' => '/home', 'views' => 2]],
                    default                                     => [],
                };
            }
        );

        $repo = new DbalPageviewRepository($db);
        $utm  = $repo->utmStats(7);

        // One grouped query per dimension (source, medium, campaign, term,
        // content, destination).
        self::assertCount(6, $captured);
        foreach ($captured as $call) {
            self::assertStringContainsString('utm_source IS NOT NULL', $call['sql'], 'only UTM traffic is counted');
            self::assertStringContainsString('GROUP BY', $call['sql']);
        }

        self::assertSame(
            [['value' => 'newsletter', 'views' => 2], ['value' => null, 'views' => 1]],
            $utm['dimensions']['source'],
        );
        self::assertSame('summer_sale', $utm['dimensions']['campaign'][0]['value']);
        self::assertSame('email', $utm['dimensions']['medium'][0]['value']);
        self::assertSame('/home', $utm['dimensions']['destination'][0]['value']);
        self::assertSame([], $utm['dimensions']['term']);
        self::assertFalse($utm['has_more']['source']);
        self::assertFalse($utm['has_more']['destination']);
    }

    public function testCustomRangeAddsUpperBoundToQueries(): void
    {
        $db = $this->createStub(\Doctrine\DBAL\Connection::class);

        $captured = [];
        $db->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured[] = ['sql' => $sql, 'params' => $params];
                return [];
            }
        );

        $range = new DateRange(
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-03'),
        );

        $repo = new DbalPageviewRepository($db);
        $repo->browserStats(7, $range);
        $repo->dailyStats(30, $range);

        self::assertCount(2, $captured);
        foreach ($captured as $call) {
            self::assertStringContainsString('created_at >= ? AND created_at < ?', $call['sql']);
            self::assertSame(['2026-08-01 00:00:00', '2026-08-04 00:00:00'], $call['params']);
        }
    }

    public function testLegacyDaysModeKeepsSingleLowerBound(): void
    {
        $db = $this->createStub(\Doctrine\DBAL\Connection::class);

        $captured = [];
        $db->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured[] = ['sql' => $sql, 'params' => $params];
                return [];
            }
        );

        $repo = new DbalPageviewRepository($db);
        $repo->browserStats(7);

        self::assertCount(1, $captured);
        self::assertStringContainsString('created_at >= ?', $captured[0]['sql']);
        self::assertStringNotContainsString('created_at < ?', $captured[0]['sql']);
        self::assertCount(1, $captured[0]['params']);
    }
}
