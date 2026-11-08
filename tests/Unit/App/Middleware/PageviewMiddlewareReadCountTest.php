<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionMethod;
use ValeApp\App\Middleware\PageviewMiddleware;
use ValeApp\Domain\Analytics\Repository\PageviewRepositoryInterface;
use ValeApp\Infrastructure\Analytics\GeoIpResolver;
use ValeApp\Infrastructure\Analytics\UserAgentParser;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\IpAnonymizer;

final class PageviewMiddlewareReadCountTest extends TestCase
{
    public function testViewsAreCountedWhenAnalyticsDisabled(): void
    {
        self::assertTrue($this->guard('shouldCountView', '0'));
    }

    public function testPageviewsAreNotLoggedWhenAnalyticsDisabled(): void
    {
        self::assertFalse($this->guard('shouldLogPageview', '0'));
    }

    public function testBothPassWhenAnalyticsEnabled(): void
    {
        self::assertTrue($this->guard('shouldCountView', '1'));
        self::assertTrue($this->guard('shouldLogPageview', '1'));
    }

    public function testNonGetRequestsAreNeverCounted(): void
    {
        $middleware = $this->middleware('0');

        try {
            $uri = $this->createStub(UriInterface::class);
            $uri->method('getPath')->willReturn('/blog/hello-world');

            $request = $this->createStub(ServerRequestInterface::class);
            $request->method('getMethod')->willReturn('POST');
            $request->method('getUri')->willReturn($uri);

            $method = new ReflectionMethod($middleware, 'shouldCountView');

            self::assertFalse((bool) $method->invoke($middleware, $request));
        } finally {
            unset($_SESSION['user_id']);
        }
    }

    private function middleware(string $analyticsEnabled): PageviewMiddleware
    {
        $db       = $this->createStub(Connection::class);
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null) => match ($key) {
                'analytics_enabled'        => $analyticsEnabled,
                'analytics_exclude_paths'  => '',
                'analytics_exclude_admins' => '1',
                default                    => $default,
            },
        );

        return new PageviewMiddleware(
            $this->createStub(PageviewRepositoryInterface::class),
            $settings,
            new GeoIpResolver(''),
            new UserAgentParser(),
            new IpAnonymizer(),
            $db,
            $this->createStub(CacheInterface::class),
        );
    }

    private function guard(string $method, string $analyticsEnabled): bool
    {
        $middleware = $this->middleware($analyticsEnabled);

        try {
            $uri = $this->createStub(UriInterface::class);
            $uri->method('getPath')->willReturn('/blog/hello-world');

            $request = $this->createStub(ServerRequestInterface::class);
            $request->method('getMethod')->willReturn('GET');
            $request->method('getUri')->willReturn($uri);

            return (bool) (new ReflectionMethod($middleware, $method))->invoke($middleware, $request);
        } finally {
            unset($_SESSION['user_id']);
        }
    }
}
