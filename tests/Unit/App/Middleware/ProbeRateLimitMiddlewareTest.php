<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use ValeApp\App\Middleware\ProbeRateLimitMiddleware;
use ValeApp\Infrastructure\Security\RateLimiter;

final class ProbeRateLimitMiddlewareTest extends TestCase
{
    private string $storagePath;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/vl_probe_rl_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);
        $this->limiter = new RateLimiter($this->storagePath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath);
    }

    public function testNormalResponsesDoNotConsumeBudget(): void
    {
        $middleware = new ProbeRateLimitMiddleware($this->limiter, 3, 600, enabled: true);

        // Far more than the limit — but every response is 200.
        for ($i = 0; $i < 10; $i++) {
            $response = $middleware->process(
                $this->request('/any/page'),
                $this->handlerReturning(200),
            );
            self::assertSame(200, $response->getStatusCode());
        }

        // Budget untouched: a single 404 still passes.
        $response = $middleware->process($this->request('/missing'), $this->handlerReturning(404));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('2', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function testErrorResponsesAreCountedAndThenThrottled(): void
    {
        $middleware = new ProbeRateLimitMiddleware($this->limiter, 3, 600, enabled: true);

        // First two 404s pass with decreasing remaining.
        $response = $middleware->process($this->request('/probe-1'), $this->handlerReturning(404));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('2', $response->getHeaderLine('X-RateLimit-Remaining'));

        $response = $middleware->process($this->request('/probe-2'), $this->handlerReturning(404));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Remaining'));

        // Third exhausts the budget → the response itself becomes 429.
        $response = $middleware->process($this->request('/probe-3'), $this->handlerReturning(404));
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('600', $response->getHeaderLine('Retry-After'));
    }

    public function testLockedOutIpNeverReachesTheStack(): void
    {
        $middleware = new ProbeRateLimitMiddleware($this->limiter, 2, 600, enabled: true);
        $middleware->process($this->request('/p1'), $this->handlerReturning(404));
        $middleware->process($this->request('/p2'), $this->handlerReturning(404));

        $called  = false;
        $handler = $this->handlerPassingThrough(static function () use (&$called): ResponseInterface {
            $called = true;
            return new Response(200);
        });

        $response = $middleware->process($this->request('/p3'), $handler);

        self::assertFalse($called, 'Locked-out IPs must not enter the middleware stack');
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function test405ResponsesCountTowardTheBudget(): void
    {
        $middleware = new ProbeRateLimitMiddleware($this->limiter, 1, 600, enabled: true);

        $response = $middleware->process($this->request('/method-probe'), $this->handlerReturning(405));
        self::assertSame(429, $response->getStatusCode());
    }

    public function testLoggedInSessionsAreNeverThrottled(): void
    {
        $middleware = new ProbeRateLimitMiddleware($this->limiter, 2, 600, enabled: true);

        // A visitor holding a vl_session cookie (admin editing, member area)
        // must never be counted or locked out — even far past the budget.
        for ($i = 0; $i < 10; $i++) {
            $response = $middleware->process(
                $this->request('/session-probe-' . $i, ['vl_session' => 'abc123']),
                $this->handlerReturning(404),
            );
            self::assertSame(404, $response->getStatusCode());
            self::assertNotSame('429', $response->getStatusCode());
        }

        // The session's 404s consumed nothing: an anonymous client still has
        // its full budget.
        $response = $middleware->process($this->request('/anon-1'), $this->handlerReturning(404));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $cookies
     */
    private function request(string $path, array $cookies = []): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '203.0.113.7']);
        $request->method('getCookieParams')->willReturn($cookies);

        return $request;
    }

    private function handlerReturning(int $status): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static fn (): ResponseInterface => new Response($status)
        );
        return $handler;
    }

    private function handlerPassingThrough(callable $fn): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback($fn);
        return $handler;
    }
}
