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

    /**
     * A deactivated owner must not keep API access. The lookup joins users on
     * is_active = 1, so the row disappears and the caller receives the same 401
     * as an unknown token — the account state is never disclosed.
     */
    public function testDeactivatedOwnerIsRejectedWith401(): void
    {
        $capturedSql    = '';
        $capturedParams = [];

        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$capturedSql, &$capturedParams) {
                $capturedSql    = $sql;
                $capturedParams = $params;

                // The users JOIN filtered the row out: the token's owner is inactive.
                return false;
            }
        );

        $request = (new RequestFactory())->createRequest('GET', '/api/v1/posts')
            ->withHeader('Authorization', 'Bearer token-of-deactivated-user');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->createStub(ResponseInterface::class));

        $response = $this->makeMiddleware($db)->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        // The query itself must exclude owners whose account is switched off.
        self::assertStringContainsString('INNER JOIN ' . VL_DB_PREFIX . 'users', $capturedSql);
        self::assertStringContainsString('u.id = t.user_id', $capturedSql);
        self::assertStringContainsString('u.is_active = 1', $capturedSql);

        // Still looked up by hash, never by the plaintext token.
        self::assertSame(hash('sha256', 'token-of-deactivated-user'), $capturedParams[0] ?? null);

        // The response must not reveal that the account was deactivated.
        $response->getBody()->rewind();
        self::assertStringContainsString('Invalid or expired token', (string) $response->getBody());
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
