<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use ValeApp\App\Middleware\ApiAuthMiddleware;

final class ApiAuthMiddlewareTest extends TestCase
{
    private function makeMiddleware(Connection $db): ApiAuthMiddleware
    {
        return new ApiAuthMiddleware($db, new \Slim\Psr7\Factory\ResponseFactory());
    }

    public function testNullAbilitiesDoesNotGrantWildcard(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'id'         => 1,
            'user_id'    => 5,
            'abilities'  => null,
            'expires_at' => null,
        ]);
        $db->method('update')->willReturn(1);

        $captured = null;
        $handler  = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function (ServerRequestInterface $req) use (&$captured): ResponseInterface {
                $captured = $req->getAttribute('api_abilities');
                return (new ResponseFactory())->createResponse(200);
            }
        );

        $request = (new RequestFactory())->createRequest('GET', '/api/v1/posts')
            ->withHeader('Authorization', 'Bearer some-token');

        $this->makeMiddleware($db)->process($request, $handler);

        self::assertSame('', $captured, 'NULL abilities must not be coerced to wildcard "*".');
    }

    public function testExplicitWildcardIsPreserved(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'id'         => 1,
            'user_id'    => 5,
            'abilities'  => '*',
            'expires_at' => null,
        ]);
        $db->method('update')->willReturn(1);

        $captured = null;
        $handler  = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function (ServerRequestInterface $req) use (&$captured): ResponseInterface {
                $captured = $req->getAttribute('api_abilities');
                return (new ResponseFactory())->createResponse(200);
            }
        );

        $request = (new RequestFactory())->createRequest('GET', '/api/v1/posts')
            ->withHeader('Authorization', 'Bearer some-token');

        $this->makeMiddleware($db)->process($request, $handler);

        self::assertSame('*', $captured);
    }

    public function testEmptyAbilitiesPassedThrough(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'id'         => 1,
            'user_id'    => 5,
            'abilities'  => '',
            'expires_at' => null,
        ]);
        $db->method('update')->willReturn(1);

        $captured = null;
        $handler  = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function (ServerRequestInterface $req) use (&$captured): ResponseInterface {
                $captured = $req->getAttribute('api_abilities');
                return (new ResponseFactory())->createResponse(200);
            }
        );

        $request = (new RequestFactory())->createRequest('GET', '/api/v1/posts')
            ->withHeader('Authorization', 'Bearer some-token');

        $this->makeMiddleware($db)->process($request, $handler);

        self::assertSame('', $captured);
    }

    public function testInvalidTokenReturns401(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);

        $request = (new RequestFactory())->createRequest('GET', '/api/v1/posts')
            ->withHeader('Authorization', 'Bearer bad-token');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->createStub(ResponseInterface::class));

        $response = $this->makeMiddleware($db)->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testMissingBearerReturns401(): void
    {
        $db = $this->createStub(Connection::class);

        $request = (new RequestFactory())->createRequest('GET', '/api/v1/posts');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $this->makeMiddleware($db)->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }
}
