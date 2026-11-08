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
 * Authentication middleware — ensures user is logged in.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $session,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start();

        if (!$this->session->has('user_id')) {
            // Redirect to login
            $uri    = $request->getUri();
            $target = $uri->getPath();
            if ($uri->getQuery() !== '') {
                $target .= '?' . $uri->getQuery();
            }

            $loginUrl = site_url('/login') . '?redirect=' . urlencode($target);
            $response = new Response(302);
            return $response->withHeader('Location', $loginUrl);
        }

        // Attach user info to request attributes
        $request = $request
            ->withAttribute('user_id', $this->session->get('user_id'))
            ->withAttribute('user_role', $this->session->get('user_role', 'subscriber'))
            ->withAttribute('user_permissions', $this->session->get('user_permissions', []));

        return $handler->handle($request);
    }
}
