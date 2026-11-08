<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ValeApp\App\Middleware\ApiAbilityMiddleware;

final class ApiAbilityMiddlewareTest extends TestCase
{
    private $request;
    private $handler;

    protected function setUp(): void
    {
        $this->request = $this->createStub(ServerRequestInterface::class);
        $this->handler = $this->createMock(RequestHandlerInterface::class);
    }

    public function testAllowsWildcard(): void
    {
        $this->request->method('getAttribute')->willReturn('*');
        $this->handler->expects(self::once())->method('handle')
            ->with($this->request)->willReturn($this->createStub(ResponseInterface::class));

        $middleware = new ApiAbilityMiddleware($this->createStub(ResponseFactoryInterface::class));
        $middleware($this->request, $this->handler, 'anything');
    }

    public function testAllowsExactMatch(): void
    {
        $this->request->method('getAttribute')
            ->willReturn('read:posts,write:products');
        $this->handler->expects(self::once())->method('handle')
            ->with($this->request)->willReturn($this->createStub(ResponseInterface::class));

        $middleware = new ApiAbilityMiddleware($this->createStub(ResponseFactoryInterface::class));
        $middleware($this->request, $this->handler, 'write:products');
    }

    public function testDeniesMissingAbility(): void
    {
        $this->request->method('getAttribute')->willReturn('read:posts');

        $response = $this->createStub(ResponseInterface::class);
        $stream   = $this->createStub(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('withHeader')->willReturnSelf();

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->expects(self::once())->method('createResponse')->with(403)->willReturn($response);
        $this->handler->expects(self::never())->method('handle');

        $middleware = new ApiAbilityMiddleware($responseFactory);
        $result     = $middleware($this->request, $this->handler, 'write:products');
        self::assertSame($response, $result);
    }
}
