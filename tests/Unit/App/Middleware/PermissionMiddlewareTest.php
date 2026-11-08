<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use ValeApp\App\Middleware\PermissionMiddleware;
use ValeApp\Infrastructure\Session\SessionManager;

final class PermissionMiddlewareTest extends TestCase
{
    private ServerRequestInterface $request;

    private RequestHandlerInterface $handler;

    public function testSuperAdminBypassesPermissionChecks(): void
    {
        $middleware = $this->createMiddleware([], ['user_role' => 'super_admin', 'user_permissions' => []]);

        $this->handler->expects(self::once())->method('handle')
            ->with($this->request)->willReturn(new Response(200));

        $middleware->process($this->request, $this->handler);
    }

    public function testPassesWithMatchingPermission(): void
    {
        $middleware = $this->createMiddleware(
            ['posts.list'],
            ['user_role' => 'editor', 'user_permissions' => ['posts.list', 'media.list']],
        );

        $this->handler->expects(self::once())->method('handle')
            ->with($this->request)->willReturn(new Response(200));

        $middleware->process($this->request, $this->handler);
    }

    public function testFallsBackToSessionPermissions(): void
    {
        $session = $this->createStub(SessionManager::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null) => match ($key) {
                'user_role'        => 'editor',
                'user_permissions' => ['pages.list'],
                default            => $default,
            },
        );

        $this->request = $this->createStub(ServerRequestInterface::class);
        $this->request->method('getAttribute')->willReturnCallback(
            static fn (string $key, mixed $default = null) => $default,
        );

        $this->handler = $this->createStub(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn(new Response(200));

        $middleware = new PermissionMiddleware($session, ['pages.list']);

        $response = $middleware->process($this->request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testReturns403WithoutMatchingPermission(): void
    {
        $middleware = $this->createMiddleware(
            ['posts.delete'],
            ['user_role' => 'author', 'user_permissions' => ['posts.list']],
        );

        $this->handler->expects(self::never())->method('handle');

        $response = $middleware->process($this->request, $this->handler);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAdminRoleIsNotBypassedAndNeedsExplicitPermission(): void
    {
        $middleware = $this->createMiddleware(
            ['roles.manage'],
            ['user_role' => 'admin', 'user_permissions' => ['users.list']],
        );

        $this->handler->expects(self::never())->method('handle');

        $response = $middleware->process($this->request, $this->handler);

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @param string[] $required
     * @param array<string, mixed> $attributes
     */
    private function createMiddleware(array $required, array $attributes): PermissionMiddleware
    {
        $this->request = $this->createStub(ServerRequestInterface::class);
        $this->request->method('getAttribute')->willReturnCallback(
            static fn (string $key, mixed $default = null) => $attributes[$key] ?? $default,
        );
        $this->handler = $this->createMock(RequestHandlerInterface::class);

        return new PermissionMiddleware(
            $this->createStub(SessionManager::class),
            $required,
        );
    }
}
