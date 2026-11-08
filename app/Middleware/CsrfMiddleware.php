<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use ValeApp\Infrastructure\Security\CsrfManager;

/**
 * CSRF protection middleware.
 *
 * Validates token on state-changing requests (POST, PUT, PATCH, DELETE).
 * Token can be submitted as a form field or HTTP header.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /** HTTP methods that require CSRF validation */
    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly CsrfManager $csrf,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Safe methods (GET, HEAD, OPTIONS) — skip validation
        if (!in_array($request->getMethod(), self::UNSAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        // Skip CSRF for API routes and webhook endpoints (they use their own auth)
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/webhook')) {
            return $handler->handle($request);
        }

        // Skip CSRF for RFC 8058 one-click unsubscribe POSTs.
        // The token in the URL is an unguessable per-subscriber secret,
        // so no CSRF token is needed (same trust level as the GET link).
        if ($request->getMethod() === 'POST' && str_contains($path, '/newsletter/unsubscribe/')) {
            return $handler->handle($request);
        }

        // Get token from form body or header
        $body  = $request->getParsedBody();
        $token = $body[$this->csrf->getTokenFieldName()] ?? '';

        if ($token === '') {
            $token = $request->getHeaderLine($this->csrf->getTokenHeaderName());
        }

        if (!$this->csrf->validate((string) $token)) {
            $response = new Response(403);
            $response->getBody()->write('CSRF token validation failed.');
            return $response;
        }

        return $handler->handle($request);
    }
}
