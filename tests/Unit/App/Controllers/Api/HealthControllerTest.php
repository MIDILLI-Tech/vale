<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Response;
use ValeApp\App\Controllers\Api\HealthController;
use ValeApp\Infrastructure\Security\RateLimiter;

final class HealthControllerTest extends TestCase
{
    private string $rootDir;
    private string $limiterDir;

    protected function setUp(): void
    {
        $this->rootDir    = sys_get_temp_dir() . '/vl_health_root_' . bin2hex(random_bytes(6));
        $this->limiterDir = sys_get_temp_dir() . '/vl_health_rl_' . bin2hex(random_bytes(6));
        mkdir($this->rootDir . '/storage/cache', 0777, true);
        mkdir($this->limiterDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->rootDir);
        $this->removeDir($this->limiterDir);
    }

    public function testPlainModeNeverTouchesTheDatabase(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willThrowException(new \RuntimeException('must not be called'));

        $controller = new HealthController($db, new RateLimiter($this->limiterDir), $this->rootDir);
        $response   = $controller->index($this->request('/api/v1/health'), new Response(200));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $payload['status']);
        self::assertArrayNotHasKey('checks', $payload);
    }

    public function testDeepModeReturnsOkWhenEverythingIsHealthy(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('getParams')->willReturn(['driver' => 'pdo_sqlite']);
        $db->method('fetchOne')->willReturn('1');

        $controller = new HealthController($db, new RateLimiter($this->limiterDir), $this->rootDir);
        $response   = $controller->index($this->request('/api/v1/health?deep=1'), new Response(200));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $payload['status']);
        self::assertArrayNotHasKey('checks', $payload, 'No per-check details are ever exposed');
    }

    public function testDeepModeReturnsDegradedWhenDatabaseIsDown(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willThrowException(new \RuntimeException('connection refused'));

        $controller = new HealthController($db, new RateLimiter($this->limiterDir), $this->rootDir);
        $response   = $controller->index($this->request('/api/v1/health?deep=1'), new Response(200));

        self::assertSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('degraded', $payload['status']);
        self::assertArrayNotHasKey('checks', $payload, 'The failure reason stays hidden');
    }

    public function testDeepModeReturnsDegradedWhenStorageIsMissing(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('getParams')->willReturn(['driver' => 'pdo_sqlite']);
        $db->method('fetchOne')->willReturn('1');

        $emptyRoot = sys_get_temp_dir() . '/vl_health_empty_' . bin2hex(random_bytes(6));
        mkdir($emptyRoot, 0777, true);

        try {
            $controller = new HealthController($db, new RateLimiter($this->limiterDir), $emptyRoot);
            $response   = $controller->index($this->request('/api/v1/health?deep=1'), new Response(200));

            self::assertSame(503, $response->getStatusCode());
            self::assertSame('degraded', json_decode((string) $response->getBody(), true)['status']);
        } finally {
            $this->removeDir($emptyRoot);
        }
    }

    public function testDeepModeIsRateLimitedButPlainModeIsNot(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('getParams')->willReturn(['driver' => 'pdo_sqlite']);
        $db->method('fetchOne')->willReturn('1');

        $controller = new HealthController($db, new RateLimiter($this->limiterDir), $this->rootDir, 2, 60);

        // Two deep probes pass…
        self::assertSame(200, $controller->index($this->request('/api/v1/health?deep=1'), new Response(200))->getStatusCode());
        self::assertSame(200, $controller->index($this->request('/api/v1/health?deep=1'), new Response(200))->getStatusCode());

        // Third is throttled…
        $denied = $controller->index($this->request('/api/v1/health?deep=1'), new Response(200));
        self::assertSame(429, $denied->getStatusCode());
        self::assertSame('60', $denied->getHeaderLine('Retry-After'));

        // …while plain health never counts.
        self::assertSame(200, $controller->index($this->request('/api/v1/health'), new Response(200))->getStatusCode());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function request(string $uri): \Psr\Http\Message\ServerRequestInterface
    {
        return (new RequestFactory())->createRequest('GET', $uri);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }
        @rmdir($dir);
    }
}
