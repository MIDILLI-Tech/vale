<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

/**
 * TwoFactorForcedMiddleware — ensures users pending 2FA enrollment
 * are restricted to the setup wizard until setup is complete.
 */
final class TwoFactorForcedMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly DbalSettingsRepository $settings,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only enforce if the user is logged in but pending 2FA setup.
        if ($this->session->get('two_factor_pending') !== true) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();

        // Strip locale prefix from path for matching, if present (e.g. /tr/account -> /account)
        $cleanPath = $path;
        $supported = array_values(array_map(
            static fn (string $locale): string => strtolower(trim($locale)),
            $this->settings->get('content_languages', 'en') ? explode(',', $this->settings->get('content_languages', 'en')) : ['en'],
        ));

        foreach ($supported as $lang) {
            if (str_starts_with($path, '/' . $lang . '/')) {
                $cleanPath = substr($path, strlen($lang) + 1);
                break;
            } elseif ($path === '/' . $lang) {
                $cleanPath = '/';
                break;
            }
        }

        // Map a localized account prefix back to its global spelling so the
        // wizard routes match the allow-list in every language
        // (e.g. routing_account_prefix_tr=hesap -> /hesap/two-factor/enable).
        $matchedLocale       = $lang ?? '';
        $globalAccountPrefix = '/' . ltrim((string) $this->settings->get('routing_account_prefix', '/account'), '/');
        $localizedAccount    = $matchedLocale !== ''
            ? $this->settings->get('routing_account_prefix_' . $matchedLocale)
            : null;

        if (is_string($localizedAccount) && $localizedAccount !== '' && $localizedAccount !== '/') {
            $localizedAccount = '/' . ltrim($localizedAccount, '/');
            if ($cleanPath === $localizedAccount || str_starts_with($cleanPath, $localizedAccount . '/')) {
                $cleanPath = rtrim($globalAccountPrefix, '/') . substr($cleanPath, strlen($localizedAccount));
            }
        }

        // 1. Never block assets, uploads, or logout
        // (Assets usually don't have locale prefixes, but logout might)
        if (
            str_starts_with($path, '/assets/')  ||
            str_starts_with($path, '/uploads/') ||
            str_starts_with($path, '/themes/')  ||
            $cleanPath === '/logout'
        ) {
            return $handler->handle($request);
        }

        // 2. Define the allowed wizard routes (using global prefixes)
        $accountPrefix = $this->settings->get('routing_account_prefix', '/account');
        $allowed       = [
            $accountPrefix . '/two-factor/setup',
            $accountPrefix . '/two-factor/forced-setup',
            $accountPrefix . '/two-factor/enable',
            $accountPrefix . '/two-factor/recovery-codes',
            $accountPrefix . '/two-factor/confirmed',
        ];

        // If current path is allowed, proceed
        if (in_array($cleanPath, $allowed, true) || in_array($path, $allowed, true)) {
            return $handler->handle($request);
        }

        // Also check if the current localized path is allowed
        foreach ($supported as $lang) {
            foreach ($allowed as $route) {
                if ($path === '/' . $lang . $route) {
                    return $handler->handle($request);
                }
            }
        }

        // 3. Otherwise, redirect to the localized setup page
        $target = site_url($accountPrefix . '/two-factor/forced-setup');

        // Prevent redirect loop if already at target but site_url() result differs from $path
        // (e.g. due to trailing slashes or base paths)
        $parsedTarget = parse_url($target, PHP_URL_PATH);
        if ($path === $parsedTarget) {
            return $handler->handle($request);
        }

        $response = new Response(302);
        return $response->withHeader('Location', $target);
    }
}
