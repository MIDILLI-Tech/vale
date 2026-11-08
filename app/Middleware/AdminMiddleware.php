<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use ValeApp\Infrastructure\Session\SessionManager;

/**
 * Admin middleware — allows any authenticated role except subscriber.
 *
 * Fine-grained route-level access is enforced by PermissionMiddleware.
 * Must be placed AFTER AuthMiddleware in the middleware stack.
 */
final class AdminMiddleware implements MiddlewareInterface
{
    /** Roles that are never allowed into /admin regardless of permissions. */
    private const BLOCKED_ROLES = ['subscriber'];

    public function __construct(
        private readonly SessionManager $session,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $role = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));

        if (in_array($role, self::BLOCKED_ROLES, true)) {
            $response = new Response(403);
            $response->getBody()->write('Access denied.');
            return $response;
        }

        return $handler->handle($request);
    }
}
