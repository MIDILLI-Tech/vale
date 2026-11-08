<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ValeApp\Application\Cron\CronService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\RateLimiter;

/**
 * Public HTTP endpoint for external cron services (e.g. cron-job.org).
 *
 * GET /cron/run?token={cron_secret}
 *
 * The token is also accepted via the Authorization header
 * (Bearer or plain) or the X-Cron-Token header, so it can be kept
 * out of access logs and Referer headers.
 *
 * Returns JSON:
 *   {"ok": true, "campaigns_sent": 2, ...}
 *   {"ok": false, "error": "Invalid token"}
 */
final class CronController
{
    public function __construct(
        private readonly CronService $cronService,
        private readonly DbalSettingsRepository $settings,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function run(Request $request, Response $response): Response
    {
        // Throttle token guessing attempts per client IP
        if (!$this->limiter->attempt('cron_token', $this->clientIp($request), 20, 300)) {
            return $this->json($response, ['ok' => false, 'error' => 'Too many attempts'], 429);
        }

        $secret = $this->settings->get('cron_secret', '') ?? '';

        // Reject if no secret is configured
        if ($secret === '') {
            return $this->json($response, ['ok' => false, 'error' => 'Cron not configured'], 403);
        }

        if (!hash_equals($secret, $this->extractToken($request))) {
            return $this->json($response, ['ok' => false, 'error' => 'Invalid token'], 403);
        }

        try {
            $result = $this->cronService->runDue();
        } catch (\Throwable $e) {
            error_log('[Cron] run failed: ' . $e->getMessage());
            return $this->json($response, ['ok' => false, 'error' => 'Cron execution failed'], 500);
        }

        return $this->json($response, array_merge(['ok' => true], $result));
    }

    /**
     * Admin manual trigger — no token required (session auth via AdminMiddleware).
     *
     * POST /admin/cron/run-now ?force_rates=1&force_update=1
     */
    public function runAdmin(Request $request, Response $response): Response
    {
        $params      = $request->getQueryParams();
        $forceRates  = !empty($params['force_rates']);
        $forceUpdate = !empty($params['force_update']);

        try {
            $result = $this->cronService->runDue($forceRates, $forceUpdate);
        } catch (\Throwable $e) {
            error_log('[Cron] admin run failed: ' . $e->getMessage());
            return $this->json($response, ['ok' => false, 'error' => 'Cron execution failed'], 500);
        }

        $lastRan   = $this->settings->get('cron_last_ran', '') ?? '';
        $formatted = '';
        if ($lastRan !== '' && is_numeric($lastRan)) {
            $formatted = date('Y-m-d H:i:s', (int) $lastRan);
        }

        return $this->json($response, array_merge(['ok' => true, 'last_ran' => $formatted], $result));
    }

    private function extractToken(Request $request): string
    {
        $authHeader = trim($request->getHeaderLine('Authorization'));
        if ($authHeader !== '') {
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
                return trim($m[1]);
            }
            return $authHeader;
        }

        $cronHeader = trim($request->getHeaderLine('X-Cron-Token'));
        if ($cronHeader !== '') {
            return $cronHeader;
        }

        return (string) ($request->getQueryParams()['token'] ?? '');
    }

    private function clientIp(Request $request): string
    {
        return vl_client_ip($request);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
