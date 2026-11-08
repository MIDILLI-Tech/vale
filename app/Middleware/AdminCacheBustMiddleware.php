<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ValeApp\Infrastructure\Cache\PageCache;

/**
 * Flushes the full-page cache after any admin write operation
 * (POST, PUT, DELETE) so visitors always see fresh content.
 */
final class AdminCacheBustMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PageCache $cache,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->cache->flush();
        }

        return $response;
    }
}
