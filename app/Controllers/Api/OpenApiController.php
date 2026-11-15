<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the machine-readable API contract at GET /api/v1/openapi.json.
 *
 * The document lives in resources/api/openapi.json so it ships with the release
 * ZIP. Its `servers` entry is rewritten per request to the current site URL, so
 * an installation mounted on a subdirectory (APP_BASE_PATH) still resolves the
 * operation paths correctly in Swagger UI, Postman or a client generator.
 *
 * tests/Unit/Api/OpenApiSpecTest.php fails whenever a route in routes/api.php
 * and the specification drift apart.
 */
final class OpenApiController
{
    private const SPEC_PATH = '/resources/api/openapi.json';

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $path = dirname(__DIR__, 3) . self::SPEC_PATH;
        $raw  = is_file($path) ? file_get_contents($path) : false;

        if ($raw === false || $raw === '') {
            $response->getBody()->write(
                (string) json_encode(
                    ['error' => 'OpenAPI specification unavailable'],
                    JSON_THROW_ON_ERROR
                )
            );

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $spec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($spec)) {
            $response->getBody()->write(
                (string) json_encode(
                    ['error' => 'OpenAPI specification is not a JSON object'],
                    JSON_THROW_ON_ERROR
                )
            );

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $spec['servers'] = [
            [
                'url'         => $this->siteUrl(),
                'description' => 'This installation (site URL including any base path).',
            ],
        ];

        $response->getBody()->write(
            (string) json_encode(
                $spec,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /**
     * Site URL without a trailing slash, e.g. `http://localhost/vale`. Falls back
     * to `/` so the document stays usable when no absolute URL is configured.
     */
    private function siteUrl(): string
    {
        if (function_exists('__require_app_url')) {
            try {
                $url = trim((string) __require_app_url());
                if ($url !== '') {
                    return rtrim($url, '/');
                }
            } catch (\Throwable) {
                // Fall through to the relative server URL below.
            }
        }

        return '/';
    }
}
