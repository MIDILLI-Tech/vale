<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ValeApp\Application\Cron\CronService;

/**
 * Page-load pseudo-cron (mode B).
 *
 * After sending the HTTP response to the client, runs due cron jobs in the
 * same process lifecycle. Uses fastcgi_finish_request() when available
 * (PHP-FPM) so the response is flushed before the work begins.
 * Falls back to register_shutdown_function() on other SAPIs.
 *
 * Only active when the `cron_mode` setting is set to `page_load`.
 * Rate-limited by `cron_interval` (minutes) via `CronService::isDue()`.
 */
final class PseudoCronMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CronService $cronService,
        private readonly string $rootDir = '',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->cronService->isDue()) {
            return $response;
        }

        // Skip if an auto-apply update lock is fresh (< 30 s) to avoid
        // a page-load timeout during a long update operation.
        if ($this->rootDir !== '') {
            $lockFile = $this->rootDir . '/storage/framework/update.lock';
            if (is_file($lockFile) && (time() - filemtime($lockFile)) < 30) {
                return $response;
            }
        }

        if (function_exists('fastcgi_finish_request')) {
            // PHP-FPM: flush response to client, then run jobs in the same process
            register_shutdown_function(function (): void {
                fastcgi_finish_request();
                try {
                    $this->cronService->runDue();
                } catch (\Throwable) {
                    // Never let a cron error bubble up after response is sent
                }
            });
        } else {
            // Other SAPIs: run after response is fully written
            register_shutdown_function(function (): void {
                try {
                    $this->cronService->runDue();
                } catch (\Throwable) {
                    // Silently ignore
                }
            });
        }

        return $response;
    }
}
