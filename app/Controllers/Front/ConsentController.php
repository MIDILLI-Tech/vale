<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Application\Consent\ConsentService;
use ValeApp\Infrastructure\Security\CsrfManager;
use ValeApp\Infrastructure\Session\SessionManager;

/**
 * Records the visitor's cookie/banner decision.
 *
 * The endpoint is a plain form POST so the existing CSRF middleware covers it.
 * Scripts are emitted server-side, so the banner reloads the page after a
 * decision instead of trying to inject tags on the client.
 */
final class ConsentController
{
    public function __construct(
        private readonly ConsentService $consent,
        private readonly SessionManager $session,
        private readonly CsrfManager $csrf,
    ) {
    }

    /**
     * Hands the banner its CSRF token at click time.
     *
     * The token is per session, so it cannot be baked into a page that is
     * shared between visitors through the page cache.
     */
    public function token(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['token' => $this->csrf->getToken()]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->consent->enabled()) {
            return $this->json($response->withStatus(409), ['ok' => false, 'error' => 'consent_disabled']);
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $revoke  = (string) ($body['revoke'] ?? '0') === '1';
        $raw     = $body['categories'] ?? [];
        $chosen  = is_array($raw) ? $raw : explode(',', (string) $raw);
        $userId  = (int) ($this->session->get('user_id') ?? 0);
        $userId  = $userId > 0 ? $userId : null;

        $state = $revoke
            ? $this->consent->revoke($userId)
            : $this->consent->save(array_values($chosen), $userId);

        return $this->json($response, [
            'ok'         => true,
            'categories' => $state->categories,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
