<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use ValeApp\Infrastructure\Security\RateLimiter;

/**
 * Count error responses (404/405) per IP and throttle repeat offenders.
 *
 * Scanners fire dozens of probes a minute against non-existent paths; each
 * one currently costs a full middleware run and an exception stack in the
 * log. A legit user mistyping a URL makes one or two 404s, so only the probe
 * storm crosses the threshold. Once throttled, the response is a bare 429
 * generated OUTSIDE the error middleware — no stack trace, no log noise.
 *
 * Sits outermost so even the 404s produced by ProbeBlockMiddleware count.
 */
final class ProbeRateLimitMiddleware implements MiddlewareInterface
{
    private const COUNTED_STATUSES = [404, 405, 406, 414, 415];

    private readonly bool $enabled;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds = 600,
        ?bool $enabled = null,
    ) {
        // Tests that sweep hundreds of synthetic routes (route-smoke suites)
        // set VLE_SKIP_RATE_LIMIT so their expected 404s don't throttle the
        // run. Unit tests for this middleware pass enabled: true explicitly.
        $this->enabled = $enabled ?? !(bool) env('VLE_SKIP_RATE_LIMIT', false);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        // Logged-in sessions (admin editing, members) are never counted or
        // throttled: they proved they are humans, and an editor screen firing
        // background requests must not lock the operator out of their own
        // site. Scanners arrive without a session cookie.
        $cookies    = $request->getCookieParams();
        $hasSession = !empty($cookies['vl_session']);

        if ($hasSession) {
            return $handler->handle($request);
        }

        $ip = vl_client_ip($request);

        // Fast path: a locked-out IP never enters the middleware stack — its
        // requests get a bare 429 instead of routing, DB, sessions, and an
        // exception stack in the log.
        if ($this->limiter->blocked('probe', $ip, $this->maxAttempts, $this->windowSeconds)) {
            return $this->throttledResponse();
        }

        $response = $handler->handle($request);

        if (in_array($response->getStatusCode(), self::COUNTED_STATUSES, true)) {
            // Only error responses consume the budget — a normal page view
            // never counts, so legit traffic cannot be throttled.
            $this->limiter->record('probe', $ip, $this->windowSeconds);

            if ($this->limiter->blocked('probe', $ip, $this->maxAttempts, $this->windowSeconds)) {
                return $this->throttledResponse();
            }

            $remaining = $this->limiter->remaining('probe', $ip, $this->maxAttempts, $this->windowSeconds);

            return $response
                ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
                ->withHeader('X-RateLimit-Remaining', (string) $remaining);
        }

        return $response;
    }

    private function throttledResponse(): Response
    {
        $response = new Response(429);
        $response->getBody()->write('Too Many Requests.');
        return $response
            ->withHeader('Retry-After', (string) $this->windowSeconds)
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', '0')
            ->withHeader('Cache-Control', 'no-store');
    }
}
