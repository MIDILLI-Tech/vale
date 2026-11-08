<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Prefixes Location headers with the app base path for all relative redirects.
 * This ensures the app works when installed in a subdirectory (e.g. /myapp/).
 */
final class BasePathMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $basePath = $_ENV['APP_BASE_PATH'] ?? '';
        if ($basePath === '') {
            $parsed   = parse_url(__require_app_url());
            $basePath = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
        }
        $basePath = rtrim($basePath, '/');

        if ($basePath === '' || !$response->hasHeader('Location')) {
            return $response;
        }

        $location = $response->getHeaderLine('Location');

        // Prefix all relative paths not already prefixed, not absolute/protocol-relative URLs
        if (
            str_starts_with($location, '/')
            && !str_starts_with($location, '//')
            && !str_starts_with($location, $basePath . '/')
            && $location !== $basePath
        ) {
            return $response->withHeader('Location', $basePath . $location);
        }

        return $response;
    }
}
