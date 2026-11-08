<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Maintenance mode middleware.
 *
 * Handles two sources of maintenance mode:
 *
 * 1. Database setting (maintenance_mode = '1'): admin-toggled, whole-site.
 * 2. Update flag file (storage/framework/maintenance.php): written by
 *    MaintenanceMode::activate() during update application. This file
 *    contains an allowed_ips list so the admin who triggered the update
 *    can still access the site.
 *
 * In either case:
 *   - /admin/* always passes through so admins can revert.
 *   - Logged-in admins (super_admin / admin) pass through.
 *   - /api/v1/health always passes through so uptime monitors,
 *     load balancers and deploy pipelines keep seeing HTTP 200.
 *   - All other visitors receive a 503 Service Unavailable page.
 *
 * Cache is automatically busted by AdminCacheBustMiddleware on any admin write.
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    private const ADMIN_ROLES = ['super_admin', 'admin'];

    public function __construct(
        private readonly DbalSettingsRepository $settings,
        private readonly Twig $twig,
        private readonly string $rootDir = '',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Health checks bypass maintenance entirely (both sources below).
        if ($this->isHealthPath($request)) {
            return $handler->handle($request);
        }

        // ── Update maintenance flag file (highest priority) ────────────
        $flagFile = $this->rootDir !== '' ? $this->rootDir . '/storage/framework/maintenance.php' : '';
        if ($flagFile !== '' && file_exists($flagFile)) {
            $flagData = @include $flagFile;
            if (is_array($flagData) && ($flagData['active'] ?? false) === true) {
                // Admins and allowed IPs always pass through
                if ($this->isAdminUser($request) || $this->isAdminPath($request)) {
                    return $handler->handle($request);
                }
                $allowedIps = $flagData['allowed_ips'] ?? [];
                $clientIp   = $this->getClientIp($request);
                if (in_array($clientIp, $allowedIps, true)) {
                    return $handler->handle($request);
                }
                $response = new Response(503);
                $response = $response->withHeader('Retry-After', '300');
                return $this->twig->render($response, 'errors/maintenance.twig', [
                    'maintenance_mode_message' => $flagData['message'] ?? null,
                ]);
            }
        }

        // ── Database setting maintenance mode ──────────────────────────
        if ($this->settings->get('maintenance_mode', '0') !== '1') {
            return $handler->handle($request);
        }

        if ($this->isAdminPath($request) || $this->isAdminUser($request)) {
            return $handler->handle($request);
        }

        // Serve maintenance page
        $response = new Response(503);
        $response = $response->withHeader('Retry-After', '3600');

        $locale  = strtolower(trim((string) ($request->getAttribute('locale')
            ?? $this->settings->get('default_locale', 'en')
            ?? 'en')));
        $message = $this->settings->getLocalized('maintenance_mode_message', $locale, '');

        return $this->twig->render($response, 'errors/maintenance.twig', [
            'maintenance_mode_message' => $message !== '' ? $message : null,
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function relativePath(ServerRequestInterface $request): string
    {
        $path         = $request->getUri()->getPath();
        $parsed       = parse_url(__require_app_url());
        $basePath     = rtrim($parsed['path'] ?? '', '/');
        $relativePath = $basePath !== '' && str_starts_with($path, $basePath)
            ? substr($path, strlen($basePath))
            : $path;

        return '/' . ltrim($relativePath, '/');
    }

    private function isHealthPath(ServerRequestInterface $request): bool
    {
        return $this->relativePath($request) === '/api/v1/health';
    }

    private function isAdminPath(ServerRequestInterface $request): bool
    {
        $relativePath = $this->relativePath($request);

        return str_starts_with($relativePath, '/admin')
            || $relativePath === '/login'
            || $relativePath === '/logout';
    }

    private function isAdminUser(ServerRequestInterface $request): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $cookieName = session_name() ?: 'vl_session';
            $cookies    = $request->getCookieParams();
            if (!empty($cookies[$cookieName]) || !empty($cookies['vl_session'])) {
                @session_name('vl_session');
                @session_start();
            }
        }
        $role = $_SESSION['user_role'] ?? 'guest';
        return in_array($role, self::ADMIN_ROLES, true);
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        return vl_client_ip($request);
    }
}
