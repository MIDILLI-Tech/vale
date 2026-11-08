<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Canonicalize trailing slashes with a 301 redirect to the slash-less URL.
 *
 * No Vale route is defined with a trailing slash, so the redirect target
 * always matches the registered route table — route prefixes and slug
 * validation are untouched. Only GET/HEAD requests are redirected (a 301
 * would drop POST bodies); everything else passes through unchanged.
 * Query strings are preserved verbatim.
 *
 * Runs entirely inside PHP, so behaviour is identical on nginx and Apache
 * with zero web-server configuration.
 */
final class TrailingSlashMiddleware implements MiddlewareInterface
{
    /**
     * @param string|null $basePath Override for the subdirectory install root
     *                              (defaults to __app_base_path()). Exposed for tests.
     */
    public function __construct(
        private readonly ?string $basePath = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if ($path === '' || $path === '/' || !str_ends_with($path, '/')) {
            return $handler->handle($request);
        }

        $target = rtrim($path, '/');

        // Never redirect the subdirectory-install homepage itself:
        // "/vale/" must keep serving, not bounce to "/vale".
        $basePath = $this->basePath ?? __app_base_path();
        if ($basePath !== '' && $path === $basePath . '/') {
            return $handler->handle($request);
        }

        if ($target === '') {
            $target = '/';
        }

        $query    = $request->getUri()->getQuery();
        $location = $target . ($query !== '' ? '?' . $query : '');

        return (new Response(301))->withHeader('Location', $location);
    }
}
