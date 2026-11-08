<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Page cache middleware — serves cached HTML before the app stack runs.
 *
 * Only caches GET requests for guest visitors (any authenticated session
 * bypasses the cache entirely). Stores the full rendered response as a
 * flat HTML file.
 */
final class PageCacheMiddleware implements MiddlewareInterface
{
    /** @var list<string>|null */
    private ?array $exclusionPatterns = null;

    public function __construct(
        private readonly PageCache $cache,
        private readonly DbalSettingsRepository $settings,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only cache GET requests
        if ($request->getMethod() !== 'GET' || !$this->cache->isEnabled()) {
            return $handler->handle($request);
        }

        // If a session cookie exists, ensure the session is started so we can
        // inspect flash data that was set on the previous (POST) request.
        $this->ensureSessionIfCookieExists();

        $url = (string) $request->getUri();

        // Never serve or store cached pages for authenticated visitors:
        // personalized output (user identity, cart badge) must not leak
        // into responses shared with other visitors of the same role.
        if ($this->hasAuthenticatedUser()) {
            return $this->markSkip($handler->handle($request));
        }

        // Skip cache for dynamic, prefix-configurable areas
        if ($this->isExcludedPath(parse_url($url, PHP_URL_PATH) ?? '/')) {
            return $this->markSkip($handler->handle($request));
        }

        // Skip cache entirely when flash messages are pending (e.g. after add-to-cart)
        if ($this->hasFlashMessages()) {
            $response = $handler->handle($request);
            // Don't cache a response that contains a one-time flash message
            return $response->withHeader('X-Vale-Cache', 'SKIP');
        }

        $locale = $request->getAttribute('locale', '');

        // Include the resolved locale in the cache key so that pages cached
        // under one locale (e.g. / -> en) are not served for another (e.g. /tr/).
        if ($locale !== '') {
            $url .= '?_locale=' . $locale;
        }

        // Try to serve from cache
        $cached = $this->cache->get($url, 'guest');
        if ($cached !== null) {
            $response = new Response(200);
            $response->getBody()->write($cached);
            return $response
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Vale-Cache', 'HIT');
        }

        // Generate response
        $response = $handler->handle($request);

        // Cache successful HTML responses (skip if page has dynamic shortcodes)
        if ($response->getStatusCode() === 200 && $this->isHtmlResponse($response)) {
            $body = (string) $response->getBody();
            if ($body !== '' && !str_contains($body, 'vl-embedded-form') && !str_contains($body, 'vl-newsletter-form')) {
                $this->cache->put($url, $body, 'guest');
            }
        }

        return $response->withHeader('X-Vale-Cache', 'MISS');
    }

    private function hasAuthenticatedUser(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']);
    }

    private function markSkip(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('X-Vale-Cache', 'SKIP');
    }

    /**
     * Exclusion patterns merge the static defaults with the configured
     * routing prefixes so that renamed prefixes (e.g. /account -> /hesap)
     * stay excluded. Localized prefixes are included per supported locale.
     *
     * @return list<string>
     */
    private function exclusionPatterns(): array
    {
        if ($this->exclusionPatterns !== null) {
            return $this->exclusionPatterns;
        }

        $patterns = [
            '/admin*',
            '/api/*',
            '/pay/*',
            '/webhooks/*',
            '/cron/*',
            '/preview/*',
            // Trailing * is essential: sub-pages such as /login/two-factor
            // embed per-session CSRF tokens and must never be cached.
            '/cart*',
            '/checkout*',
            '/account*',
            '/login*',
            '/register*',
            '/logout',
        ];

        try {
            $prefixTypes = ['cart', 'checkout', 'membership', 'account', 'login', 'register', 'logout',
                            'forgot_password', 'reset_password', 'verify_email'];
            $multiLang   = ($this->settings->get('multi_language_content', '0') ?? '0') === '1';
            $locales     = [];

            if ($multiLang) {
                $translator = Translator::getInstance();
                $locales    = $translator?->getSupportedLocales() ?? [];
            }

            foreach ($prefixTypes as $type) {
                $default   = '/' . $type;
                $globalKey = 'routing_' . $type . '_prefix';
                $keys      = [$globalKey];

                foreach ($locales as $locale) {
                    $keys[] = $globalKey . '_' . $locale;
                }

                foreach ($keys as $key) {
                    $value = $this->settings->get($key, $default);
                    if (!is_string($value) || trim($value) === '' || trim($value) === '/') {
                        continue;
                    }
                    $pattern    = '/' . ltrim(trim($value), '/');
                    $patterns[] = rtrim($pattern, '/') . '*';
                }
            }
        } catch (\Throwable) {
            // Settings unavailable (e.g. pre-install state) — keep defaults
        }

        $this->exclusionPatterns = array_values(array_unique($patterns));

        return $this->exclusionPatterns;
    }

    private function isExcludedPath(string $path): bool
    {
        foreach ($this->exclusionPatterns() as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function isHtmlResponse(ResponseInterface $response): bool
    {
        $contentType = $response->getHeaderLine('Content-Type');
        return str_contains($contentType, 'text/html') || $contentType === '';
    }

    /**
     * Start the native PHP session if a session cookie was sent but the
     * session has not been opened yet. This lets us peek at flash data
     * without waiting for SessionManager (which runs later in the stack).
     */
    private function ensureSessionIfCookieExists(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!empty($_COOKIE[session_name()] ?? $_COOKIE['vl_session'] ?? null)) {
            session_name('vl_session');
            session_start();
        }
    }

    private function hasFlashMessages(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        foreach (['success', 'error', 'info', 'warning'] as $type) {
            if (!empty($_SESSION['flash_' . $type])) {
                return true;
            }
        }
        return false;
    }
}
