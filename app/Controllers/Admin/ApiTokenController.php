<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Admin: manage personal API tokens.
 * Tokens are stored as SHA-256 hashes; the plaintext is shown once at creation.
 */
final class ApiTokenController
{
    private const TABLE = VL_DB_PREFIX . 'api_tokens';

    public function __construct(
        private readonly Translator $translator,
        private readonly Connection $db,
        private readonly SessionManager $session,
    ) {
    }

    public function store(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');
        $body   = (array) $request->getParsedBody();
        $name   = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            $this->session->set('flash_error', $this->translator->get(
                'Token name is required.'
            ));
            return $response->withHeader('Location', admin_url('/settings?tab=advanced&subtab=api-tokens'))->withStatus(302);
        }

        // Generate a 32-byte random token (64 hex chars when bin2hex'd)
        $plainToken = bin2hex(random_bytes(32));
        $hashed     = hash('sha256', $plainToken);

        $abilities  = trim((string) ($body['abilities'] ?? '*'));
        $expiresAt  = null;
        if (!empty($body['expires_at'])) {
            $expiresAt = date('Y-m-d H:i:s', strtotime($body['expires_at']));
        }

        $this->db->insert(self::TABLE, [
            'user_id'    => $userId,
            'name'       => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            'token'      => $hashed,
            'abilities'  => $abilities,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Store the plaintext token in session so it can be displayed once
        $this->session->set('new_api_token', $plainToken);
        $this->session->set('flash_success', $this->translator->get(
            'Token created. Copy it now — it will not be shown again.'
        ));

        return $response->withHeader('Location', admin_url('/settings?tab=advanced&subtab=api-tokens'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $userId  = (int) $this->session->get('user_id');
        $tokenId = (int) $args['id'];

        // Only allow deleting own tokens
        $this->db->delete(self::TABLE, ['id' => $tokenId, 'user_id' => $userId]);

        $this->session->set('flash_success', $this->translator->get('Token revoked.'));
        return $response->withHeader('Location', admin_url('/settings?tab=advanced&subtab=api-tokens'))->withStatus(302);
    }
}
