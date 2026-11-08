<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\AnalyticsController;
use ValeApp\Domain\Analytics\Repository\PageviewRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class AnalyticsUtmLabelTest extends TestCase
{
    public function testIndexBuildsFacetPanelsFromUtmDimensions(): void
    {
        $pageviews = $this->pageviewStub([
            'dimensions' => [
                'source'      => [['value' => 'chatgpt.com', 'views' => 15], ['value' => null, 'views' => 4]],
                'medium'      => [['value' => 'referral', 'views' => 15]],
                'campaign'    => [['value' => 'spring-sale', 'views' => 3]],
                'term'        => [],
                'content'     => [],
                'destination' => [['value' => '/midi-web-tool', 'views' => 7]],
            ],
            'has_more'   => ['source' => true, 'medium' => false, 'campaign' => false, 'term' => false, 'content' => false, 'destination' => false],
            'filters'    => [],
        ]);

        $rendered   = [];
        $controller = $this->controller($pageviews, $rendered);

        $controller->index(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/analytics'),
            new Response(),
        );

        self::assertSame('admin/analytics/index.twig', $rendered['template']);

        $panels = $rendered['data']['utm_panels'];

        // term / content are empty and unfiltered → dropped so the card keeps
        // its four-panel layout on sites that never set them.
        self::assertSame(['source', 'medium', 'campaign', 'destination'], array_column($panels, 'key'));

        // A missing dimension becomes the "(not set)" bucket: label stays null
        // so Twig can translate it, wire carries the reserved token.
        self::assertSame(
            [
                ['wire' => 'chatgpt.com', 'label' => 'chatgpt.com', 'views' => 15, 'selected' => false],
                ['wire' => '__not_set__', 'label' => null, 'views' => 4, 'selected' => false],
            ],
            $panels[0]['rows'],
        );

        self::assertTrue($panels[0]['has_more']);
        self::assertFalse($panels[2]['has_more']);
        self::assertSame('/midi-web-tool', $panels[3]['rows'][0]['label']);
        self::assertSame([], $rendered['data']['utm_filters']);
    }

    public function testUtmPanelEndpointReturnsFacetStateAndMarksSelection(): void
    {
        $pageviews = $this->pageviewStub([
            'dimensions' => [
                'source'      => [['value' => 'newsletter', 'views' => 5]],
                'medium'      => [],
                'campaign'    => [['value' => 'spring-sale', 'views' => 5], ['value' => null, 'views' => 2]],
                'term'        => [],
                'content'     => [],
                'destination' => [],
            ],
            'has_more'   => [],
            'filters'    => [],
        ]);

        $fetched    = [];
        $rendered   = [];
        $controller = $this->controller($pageviews, $rendered, $fetched);

        $response = $controller->utmPanel(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/admin/analytics/utm-panel')
                ->withQueryParams(['campaign' => 'spring-sale', 'dir' => 'asc']),
            new Response(),
        );

        self::assertSame('admin/analytics/rows/utm_body.twig', $fetched[0]['template']);
        self::assertSame(['campaign' => 'spring-sale'], $fetched[0]['data']['utm_filters']);
        self::assertSame('asc', $fetched[0]['data']['utm_dir']);

        // The chips are rendered separately so they can sit in the card header.
        self::assertSame('admin/analytics/rows/utm_filters.twig', $fetched[1]['template']);
        self::assertSame(['campaign' => 'spring-sale'], $fetched[1]['data']['utm_filters']);

        $campaign = null;
        foreach ($fetched[0]['data']['utm_panels'] as $panel) {
            if ($panel['key'] === 'campaign') {
                $campaign = $panel;
            }
        }

        self::assertNotNull($campaign);
        self::assertTrue($campaign['rows'][0]['selected'], 'the active facet value is marked');
        self::assertFalse($campaign['rows'][1]['selected']);

        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame(['campaign' => 'spring-sale'], $payload['filters']);
        self::assertSame('asc', $payload['dir']);
        self::assertArrayHasKey('filters_html', $payload);
    }

    public function testNullBucketIsSentAsEmptyFilterAndSelectsNotSetRow(): void
    {
        $pageviews = $this->pageviewStub([
            'dimensions' => ['campaign' => [['value' => null, 'views' => 2], ['value' => 'x', 'views' => 1]]],
            'has_more'   => [],
            'filters'    => [],
        ]);

        $fetched    = [];
        $rendered   = [];
        $controller = $this->controller($pageviews, $rendered, $fetched);

        $controller->utmPanel(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/admin/analytics/utm-panel')
                ->withQueryParams(['campaign' => '__not_set__']),
            new Response(),
        );

        self::assertSame(['campaign' => ''], $fetched[0]['data']['utm_filters'], 'the wire token maps to the NULL bucket');

        foreach ($fetched[0]['data']['utm_panels'] as $panel) {
            if ($panel['key'] === 'campaign') {
                self::assertTrue($panel['rows'][0]['selected']);
                self::assertFalse($panel['rows'][1]['selected']);
            }
        }
    }

    /**
     * @param array<string, mixed> $utmStats
     */
    private function pageviewStub(array $utmStats): PageviewRepositoryInterface
    {
        $pageviews = $this->createStub(PageviewRepositoryInterface::class);
        $pageviews->method('countDays')->willReturn(120);
        $pageviews->method('countToday')->willReturn(12);
        $pageviews->method('countUniqueToday')->willReturn(9);
        $pageviews->method('countOnline')->willReturn(3);
        $pageviews->method('topPages')->willReturn([]);
        $pageviews->method('topReferrers')->willReturn([]);
        $pageviews->method('getSessionMetrics')->willReturn([
            'avg_duration' => 42.0,
            'bounce_rate'  => 55.0,
            'avg_pages'    => 2.5,
        ]);
        $pageviews->method('entryPages')->willReturn([]);
        $pageviews->method('exitPages')->willReturn([]);
        $pageviews->method('utmStats')->willReturn($utmStats);
        $pageviews->method('browserStats')->willReturn([]);
        $pageviews->method('deviceStats')->willReturn([]);
        $pageviews->method('countryStats')->willReturn([]);
        $pageviews->method('cityStats')->willReturn([]);
        $pageviews->method('languageStats')->willReturn([]);
        $pageviews->method('dailyStats')->willReturn([]);
        $pageviews->method('statusCodeStats')->willReturn([]);
        $pageviews->method('statusCodeCounts')->willReturn([]);
        $pageviews->method('avgDurationByPath')->willReturn([]);

        return $pageviews;
    }

    /**
     * @param array{template?: string, data?: array<string, mixed>} $rendered
     * @param list<array{template: string, data: array<string, mixed>}> $fetched
     */
    private function controller(PageviewRepositoryInterface $pageviews, ?array &$rendered = null, ?array &$fetched = null): AnalyticsController
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([]);

        $settings     = $this->createStub(DbalSettingsRepository::class);
        $contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $contentTypes->method('findActiveCustom')->willReturn([]);

        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            static function (Response $response, string $template, array $data) use (&$rendered): Response {
                $rendered = ['template' => $template, 'data' => $data];

                return $response;
            }
        );
        $twig->method('fetch')->willReturnCallback(
            static function (string $template, array $data = []) use (&$fetched): string {
                $fetched[] = ['template' => $template, 'data' => $data];

                return '';
            }
        );

        return new AnalyticsController($twig, $pageviews, $settings, $db, $contentTypes);
    }
}
