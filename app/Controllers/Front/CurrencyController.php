<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

/**
 * POST /set-currency
 *
 * Stores the visitor's preferred currency in the session, validated against the
 * admin-configured allowed/blocked currency lists.
 */
final class CurrencyController
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly DbalSettingsRepository $settings,
    ) {
    }

    public function set(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $code     = strtoupper(trim($body['currency'] ?? ''));
        $referer  = $request->getHeaderLine('Referer') ?: '/';

        if ($code !== '' && in_array($code, $this->knownCurrencies(), true)) {
            $allowed = $this->parseList($this->settings->get('allowed_currencies', ''));
            $blocked = $this->parseList($this->settings->get('blocked_currencies', ''));
            $default = strtoupper($this->settings->get('currency', 'USD') ?? 'USD');

            $passesAllowed = $code === $default || in_array($code, $allowed, true);
            $passesBlocked = !in_array($code, $blocked, true);

            if ($passesAllowed && $passesBlocked) {
                // Switching back to the store default -> clear session override
                if ($code === $default) {
                    $this->session->remove('selected_currency');
                } else {
                    $this->session->set('selected_currency', $code);
                }
            }
        }

        return $response->withHeader('Location', $referer)->withStatus(302);
    }

    /** @return string[] */
    private function parseList(string $raw): array
    {
        return array_values(array_filter(array_map('strtoupper', array_map('trim', explode(',', $raw)))));
    }

    /** @return string[] */
    private function knownCurrencies(): array
    {
        $referenceData = require dirname(__DIR__, 3) . '/config/reference-data.php';
        $currencies    = is_array($referenceData['currencies'] ?? null) ? $referenceData['currencies'] : [];

        $codes = [];
        foreach ($currencies as $currency) {
            $code = strtoupper(trim((string) ($currency['code'] ?? '')));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }
}
