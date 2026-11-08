<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Api;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use ValeApp\Infrastructure\Security\RateLimiter;

/**
 * Health endpoint — no auth required.
 *
 * Two modes:
 * - Plain (`GET /api/v1/health`): static liveness proof (version +
 *   timestamp). Never touches the database, so a down DB cannot flip it.
 *   Used by the Docker HEALTHCHECK.
 * - Deep (`GET /api/v1/health?deep=1`): verifies DB reachability, the
 *   storage tree, and the cache tree for external uptime monitors. Returns
 *   HTTP 200 with `status: ok`, or 503 with `status: degraded`. On purpose
 *   there are NO per-check details — an outsider learns nothing beyond
 *   "up / not up". Deep mode is mini rate-limited per IP.
 */
final class HealthController
{
    public function __construct(
        private readonly Connection $db,
        private readonly RateLimiter $limiter,
        private readonly string $rootDir,
        private readonly int $deepLimit = 30,
        private readonly int $deepWindowSeconds = 60,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = [
            'status'    => 'ok',
            'version'   => $_ENV['APP_VERSION'] ?? '1.0.0',
            'timestamp' => time(),
        ];

        $params = $request->getQueryParams();
        $deep   = isset($params['deep']) && in_array(strtolower((string) $params['deep']), ['1', 'true', 'yes'], true);

        if (!$deep) {
            return $this->json($response, $payload, 200);
        }

        if (!$this->limiter->attempt('health', vl_client_ip($request), $this->deepLimit, $this->deepWindowSeconds)) {
            $denied = $this->json($response, $payload, 429);
            return $denied->withHeader('Retry-After', (string) $this->deepWindowSeconds);
        }

        $healthy = $this->checkDatabase() && $this->checkWritable('storage') && $this->checkWritable('storage/cache');

        $payload['status'] = $healthy ? 'ok' : 'degraded';

        return $this->json($response, $payload, $healthy ? 200 : 503);
    }

    /**
     * Database reachability with a hard timeout so a hung connection turns
     * into `degraded` instead of hanging the request.
     */
    private function checkDatabase(): bool
    {
        try {
            $params = $this->db->getParams();
            $driver = strtolower((string) ($params['driver'] ?? ''));

            if (str_contains($driver, 'mysql')) {
                // Short connect/read timeouts — this is a liveness check.
                $this->db->executeStatement('SET SESSION max_execution_time = 2000');
            }

            $this->db->fetchOne('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param 'storage'|'storage/cache' $relative
     */
    private function checkWritable(string $relative): bool
    {
        $dir = rtrim($this->rootDir, '/\\') . '/' . $relative;
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        // Prove the tree is really writable (permissions can lie on NFS and
        // some FUSE mounts). Random suffix avoids clashes under concurrency.
        $probe = $dir . '/.health-' . bin2hex(random_bytes(4));
        $ok    = @file_put_contents($probe, '1') !== false;
        if ($ok) {
            @unlink($probe);
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $out = new Response($status);
        $out->getBody()->write((string) json_encode($payload, JSON_THROW_ON_ERROR));
        return $out->withHeader('Content-Type', 'application/json');
    }
}
