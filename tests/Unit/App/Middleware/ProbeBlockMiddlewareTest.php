<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use ValeApp\App\Middleware\ProbeBlockMiddleware;

final class ProbeBlockMiddlewareTest extends TestCase
{
    private ProbeBlockMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new ProbeBlockMiddleware();
    }

    public function testWordpressXmlrpcProbeIsDropped(): void
    {
        $handler = $this->handlerThatMustNotRun();

        $response = $this->middleware->process(
            $this->requestWithMethodAndPath('POST', '/xmlrpc.php'),
            $handler,
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[DataProvider('blockedPathProvider')]
    public function testBlockedPathsReturnBare404(string $path): void
    {
        $response = $this->middleware->process(
            $this->requestWithMethodAndPath('GET', $path),
            $this->handlerThatMustNotRun(),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedPathProvider(): array
    {
        return [
            'wp-login'            => ['/wp-login.php'],
            'wp-cron'             => ['/wp-cron.php'],
            'wp-admin'            => ['/wp-admin/edit.php'],
            'wp-content'          => ['/wp-content/plugins/x.js'],
            'env file'            => ['/.env'],
            'env backup'          => ['/.env.bak'],
            'env nested'          => ['/.env.local'],
            'git config'          => ['/.git/config'],
            'phpinfo'             => ['/phpinfo.php'],
            'phar probe'          => ['/index.phar'],
            'phantom php file'    => ['/config.php'],
            'phantom php nested'  => ['/assets/vendor.php'],
            'cgi-bin'             => ['/cgi-bin/test-cgi'],
            'spring actuator'     => ['/actuator/env'],
            'composer lock'       => ['/composer.lock'],
            'storage leak'        => ['/storage/logs/errors.log'],
            'dlink boaform'       => ['/boaform/admin/formLogin'],
        ];
    }

    public function testRealContentPathPassesThrough(): void
    {
        $called  = false;
        $handler = $this->handlerPassingThrough(function () use (&$called) {
            $called = true;
            return new Response(200);
        });

        $response = $this->middleware->process(
            $this->requestWithMethodAndPath('GET', '/blog/my-post'),
            $handler,
        );

        self::assertTrue($called, 'Legit paths must reach the app');
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAcmeChallengePassesThrough(): void
    {
        $called  = false;
        $handler = $this->handlerPassingThrough(function () use (&$called) {
            $called = true;
            return new Response(200);
        });

        $response = $this->middleware->process(
            $this->requestWithMethodAndPath('GET', '/.well-known/acme-challenge/token123'),
            $handler,
        );

        self::assertTrue($called, 'Certbot ACME challenges must never be blocked');
        self::assertSame(200, $response->getStatusCode());
    }

    #[DataProvider('genuineEntryScriptProvider')]
    public function testGenuineEntryScriptsPassThrough(string $path): void
    {
        $called  = false;
        $handler = $this->handlerPassingThrough(function () use (&$called) {
            $called = true;
            return new Response(200);
        });

        $response = $this->middleware->process(
            $this->requestWithMethodAndPath('GET', $path),
            $handler,
        );

        self::assertTrue($called, "Entry script {$path} must pass through");
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function genuineEntryScriptProvider(): array
    {
        return [
            'install' => ['/install.php'],
            'router'  => ['/router.php'],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function requestWithMethodAndPath(string $method, string $path): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturn('');

        return $request;
    }

    private function handlerThatMustNotRun(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')
            ->willReturnCallback(static function () {
                throw new \RuntimeException('Handler must not run for blocked probes');
            });
        return $handler;
    }

    private function handlerPassingThrough(callable $fn): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback($fn);
        return $handler;
    }
}
