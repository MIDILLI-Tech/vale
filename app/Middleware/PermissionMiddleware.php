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
 * Permission middleware — checks that the authenticated user holds at least
 * one of the given permission slugs.
 *
 * Only super_admin bypasses permission checks. The admin role must hold its
 * permissions in the database (seeded with every permission by default, but
 * revocable from Admin -> Roles).
 *
 * Must be placed AFTER AuthMiddleware in the middleware stack.
 *
 * Usage in routes:
 *   $group->add(new PermissionMiddleware($session, ['posts.list']));
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    /** These roles bypass all permission checks. */
    private const BYPASS_ROLES = ['super_admin'];

    /**
     * @param string[] $required  At least one of these slugs must be present.
     */
    public function __construct(
        private readonly SessionManager $session,
        private readonly array $required,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $role = $request->getAttribute('user_role', $this->session->get('user_role', 'subscriber'));

        // Admins always pass through
        if (in_array($role, self::BYPASS_ROLES, true)) {
            return $handler->handle($request);
        }

        /** @var string[] $userPerms */
        $userPerms = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));

        foreach ($this->required as $slug) {
            if (in_array($slug, $userPerms, true)) {
                return $handler->handle($request);
            }
        }

        $response = new Response(403);
        $response->getBody()->write('You do not have permission to access this area.');
        return $response;
    }
}
