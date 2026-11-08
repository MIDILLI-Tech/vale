<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Security;

use ValeApp\Infrastructure\Session\SessionManager;

/**
 * CSRF token manager.
 *
 * Generates and validates per-session CSRF tokens.
 */
class CsrfManager
{
    private const SESSION_KEY = '_csrf_tokens';

    public function __construct(
        private readonly SessionManager $session,
        private readonly array $config,
    ) {
    }

    /**
     * Generate (or retrieve) the current CSRF token.
     */
    public function getToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if ($token === null) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Validate a submitted token against the session token.
     */
    public function validate(string $submittedToken): bool
    {
        $storedToken = $this->session->get(self::SESSION_KEY);

        if ($storedToken === null || $submittedToken === '') {
            return false;
        }

        return hash_equals($storedToken, $submittedToken);
    }

    /**
     * Regenerate the token (call after successful form submission for extra safety).
     */
    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);
        return $token;
    }

    public function getTokenFieldName(): string
    {
        return $this->config['token_name'] ?? '_csrf_token';
    }

    public function getTokenHeaderName(): string
    {
        return $this->config['header_name'] ?? 'X-CSRF-Token';
    }
}
