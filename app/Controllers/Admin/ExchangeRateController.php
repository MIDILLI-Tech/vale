<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;
use ValeApp\Infrastructure\Session\SessionManager;

final class ExchangeRateController
{
    public function __construct(
        private readonly ExchangeRateService $exchangeRates,
        private readonly SessionManager $session,
    ) {
    }

    /** POST /admin/exchange-rates/refresh — AJAX returns JSON, plain POST redirects (legacy fallback) */
    public function refresh(Request $request, Response $response): Response
    {
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        // Accept unsaved form values so refresh works without a prior Save.
        $body      = $request->getParsedBody();
        $overrides = [];
        if (is_array($body)) {
            foreach (['fx_provider', 'fx_api_key', 'fx_cache_hours', 'currency'] as $key) {
                if (array_key_exists($key, $body) && is_scalar($body[$key])) {
                    $overrides[$key] = (string) $body[$key];
                }
            }
        }

        try {
            $this->exchangeRates->fetchAndCache($overrides);
            $updatedAt = $this->exchangeRates->getUpdatedAt() ?? '';
            $base      = $this->exchangeRates->getBaseCurrency();

            if ($isAjax) {
                $response->getBody()->write((string) json_encode([
                    'ok'         => true,
                    'message'    => 'Exchange rates refreshed successfully.',
                    'updated_at' => $updatedAt,
                    'base'       => $base,
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $this->session->set('flash_success', 'Exchange rates refreshed successfully.');
        } catch (\Throwable $e) {
            if ($isAjax) {
                $response->getBody()->write((string) json_encode([
                    'ok'      => false,
                    'message' => 'Failed to refresh rates: ' . $e->getMessage(),
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
            }
            $this->session->set('flash_error', 'Failed to refresh rates: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $response
            ->withHeader('Location', admin_url('/settings?subtab=currency#tab-ecommerce'))
            ->withStatus(302);
    }
}
