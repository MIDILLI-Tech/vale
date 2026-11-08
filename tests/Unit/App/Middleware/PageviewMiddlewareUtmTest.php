<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionMethod;
use Slim\Psr7\Response;
use ValeApp\App\Middleware\PageviewMiddleware;
use ValeApp\Domain\Analytics\Entity\Pageview;
use ValeApp\Domain\Analytics\Repository\PageviewRepositoryInterface;
use ValeApp\Infrastructure\Analytics\GeoIpResolver;
use ValeApp\Infrastructure\Analytics\UserAgentParser;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\IpAnonymizer;

final class PageviewMiddlewareUtmTest extends TestCase
{
    public function testTrackPersistsUtmParametersAndPagePath(): void
    {
        $saved = null;

        $pageviews = $this->createStub(PageviewRepositoryInterface::class);
        $pageviews->method('save')->willReturnCallback(
            static function (Pageview $pageview) use (&$saved): Pageview {
                $saved = $pageview;

                return $pageview;
            }
        );

        $middleware = $this->middleware($pageviews);

        $request = $this->request('/landing', [
            'utm_source'   => 'Newsletter',
            'utm_medium'   => 'email',
            'utm_campaign' => 'Spring Sale',
            'utm_term'     => 'php cms',
            'utm_content'  => 'hero-banner',
        ]);

        (new ReflectionMethod($middleware, 'track'))->invoke($middleware, $request, new Response());

        self::assertInstanceOf(Pageview::class, $saved);
        // UTM values are stored verbatim (only trimmed) — "Email" stays
        // distinct from "email" in the dashboard.
        self::assertSame('Newsletter', $saved->getUtmSource());
        self::assertSame('email', $saved->getUtmMedium());
        self::assertSame('Spring Sale', $saved->getUtmCampaign());
        self::assertSame('php cms', $saved->getUtmTerm());
        self::assertSame('hero-banner', $saved->getUtmContent());
        self::assertSame('/landing', $saved->getPagePath());
    }

    public function testTrackLeavesUtmColumnsNullWithoutParameters(): void
    {
        $saved = null;

        $pageviews = $this->createStub(PageviewRepositoryInterface::class);
        $pageviews->method('save')->willReturnCallback(
            static function (Pageview $pageview) use (&$saved): Pageview {
                $saved = $pageview;

                return $pageview;
            }
        );

        $middleware = $this->middleware($pageviews);
        $request    = $this->request('/about', []);

        (new ReflectionMethod($middleware, 'track'))->invoke($middleware, $request, new Response());

        self::assertInstanceOf(Pageview::class, $saved);
        self::assertNull($saved->getUtmSource());
        self::assertNull($saved->getUtmMedium());
        self::assertNull($saved->getUtmCampaign());
        self::assertNull($saved->getUtmTerm());
        self::assertNull($saved->getUtmContent());
        self::assertSame('/about', $saved->getPagePath());
    }

    public function testExtractUtmTrimsValuesAndMapsEmptyOrNonScalarToNull(): void
    {
        $middleware = $this->middleware($this->createStub(PageviewRepositoryInterface::class));
        $request    = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'utm_source'   => '  Twitter  ',
            'utm_medium'   => '',
            'utm_campaign' => '   ',
            'utm_content'  => ['nested'],
        ]);

        $utm = (new ReflectionMethod($middleware, 'extractUtm'))->invoke($middleware, $request);

        self::assertSame(['Twitter', null, null, null, null], $utm);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(string $path, array $query): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn(http_build_query($query));
        $uri->method('getHost')->willReturn('example.com');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '203.0.113.7']);

        return $request;
    }

    private function middleware(PageviewRepositoryInterface $pageviews): PageviewMiddleware
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => match ($key) {
                'ip_privacy_mode'       => 'mask',
                'analytics_geolocation' => GeoIpResolver::MODE_NONE,
                default                 => $default,
            }
        );

        return new PageviewMiddleware(
            $pageviews,
            $settings,
            new GeoIpResolver(),
            new UserAgentParser(),
            new IpAnonymizer(),
            $this->createStub(Connection::class),
            $this->createStub(CacheInterface::class),
        );
    }
}
