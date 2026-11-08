<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use ValeApp\App\Middleware\TrailingSlashMiddleware;

class TrailingSlashMiddlewareTest extends TestCase
{
    private function passthroughHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));

        return $handler;
    }

    private function neverCalledHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        return $handler;
    }

    public function testGetTrailingSlashRedirectsToSlashLess(): void
    {
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/about/');
        $middleware = new TrailingSlashMiddleware();

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/about', $response->getHeaderLine('Location'));
    }

    public function testShopIndexTrailingSlashRedirects(): void
    {
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/shop/');
        $middleware = new TrailingSlashMiddleware();

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/shop', $response->getHeaderLine('Location'));
    }

    public function testNestedPathAndQueryStringArePreserved(): void
    {
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/parent/child/?page=2');
        $middleware = new TrailingSlashMiddleware();

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/parent/child?page=2', $response->getHeaderLine('Location'));
    }

    public function testLocalePrefixIsPreserved(): void
    {
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/en/hakkimizda/');
        $middleware = new TrailingSlashMiddleware();

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/en/hakkimizda', $response->getHeaderLine('Location'));
    }

    public function testHeadTrailingSlashRedirects(): void
    {
        $request    = (new ServerRequestFactory())->createServerRequest('HEAD', '/about/');
        $middleware = new TrailingSlashMiddleware();

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/about', $response->getHeaderLine('Location'));
    }

    public function testNoTrailingSlashPassesThrough(): void
    {
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/about');
        $middleware = new TrailingSlashMiddleware();

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRootPassesThrough(): void
    {
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $middleware = new TrailingSlashMiddleware();

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPostTrailingSlashPassesThrough(): void
    {
        $request    = (new ServerRequestFactory())->createServerRequest('POST', '/about/');
        $middleware = new TrailingSlashMiddleware();

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSubdirectoryHomepagePassesThrough(): void
    {
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/vale/');
        $middleware = new TrailingSlashMiddleware('/vale');

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSubdirectoryDeepPathRedirectsVerbatim(): void
    {
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/vale/shop/x/');
        $middleware = new TrailingSlashMiddleware('/vale');

        $response = $middleware->process($request, $this->neverCalledHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/vale/shop/x', $response->getHeaderLine('Location'));
    }
}
