<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use ValeApp\Infrastructure\Security\RateLimiter;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly string $key,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds = 60,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = vl_client_ip($request);

        if (!$this->limiter->attempt($this->key, $ip, $this->maxAttempts, $this->windowSeconds)) {
            $remaining = $this->limiter->remaining($this->key, $ip, $this->maxAttempts, $this->windowSeconds);
            $response  = new Response(429);
            $response->getBody()->write('Too Many Requests. Please try again later.');
            return $response
                ->withHeader('Retry-After', (string) $this->windowSeconds)
                ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
                ->withHeader('X-RateLimit-Remaining', (string) $remaining);
        }

        $remaining = $this->limiter->remaining($this->key, $ip, $this->maxAttempts, $this->windowSeconds);
        $response  = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining);
    }
}
