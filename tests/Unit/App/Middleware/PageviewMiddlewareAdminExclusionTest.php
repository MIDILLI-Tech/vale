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

final class PageviewMiddlewareAdminExclusionTest extends TestCase
{
    private Connection $db;

    private DbalSettingsRepository $settings;

    protected function setUp(): void
    {
        $this->db = $this->createStub(Connection::class);
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null) => match ($key) {
                'analytics_enabled'        => '1',
                'analytics_exclude_paths'  => '',
                'analytics_exclude_admins' => '1',
                default                    => $default,
            },
        );
        $this->settings = $settings;
    }

    public function testEditorPageviewsAreTracked(): void
    {
        $this->db->method('fetchOne')->willReturn(['slug' => 'editor']);

        self::assertTrue($this->shouldTrack());
    }

    public function testAdminPageviewsAreExcluded(): void
    {
        $this->db->method('fetchOne')->willReturn(['slug' => 'admin']);

        self::assertFalse($this->shouldTrack());
    }

    public function testSuperAdminPageviewsAreExcluded(): void
    {
        $this->db->method('fetchOne')->willReturn(['slug' => 'super_admin']);

        self::assertFalse($this->shouldTrack());
    }

    public function testDeletedUserRowStillCountsAsVisitor(): void
    {
        $this->db->method('fetchOne')->willReturn(false);

        self::assertTrue($this->shouldTrack());
    }

    private function shouldTrack(): bool
    {
        $middleware = new PageviewMiddleware(
            $this->createStub(PageviewRepositoryInterface::class),
            $this->settings,
            new GeoIpResolver(''),
            new UserAgentParser(),
            new IpAnonymizer(),
            $this->db,
            $this->createStub(CacheInterface::class),
        );

        $_SESSION['user_id'] = 42;

        try {
            $uri = $this->createStub(UriInterface::class);
            $uri->method('getPath')->willReturn('/blog/hello-world');

            $request = $this->createStub(ServerRequestInterface::class);
            $request->method('getMethod')->willReturn('GET');
            $request->method('getUri')->willReturn($uri);

            $method = new ReflectionMethod($middleware, 'shouldLogPageview');

            return (bool) $method->invoke($middleware, $request);
        } finally {
            unset($_SESSION['user_id']);
        }
    }
}
