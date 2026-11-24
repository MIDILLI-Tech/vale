<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Stream;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Security\CspNonce;

/**
 * Adds security-related HTTP response headers to every response.
 *
 * Scripts are whitelisted by nonce: templates render
 * `nonce="{{ csp_nonce }}"`, which carries the placeholder value that this
 * middleware replaces with a fresh per-request nonce. Inline event handlers
 * keep working through `script-src-attr 'unsafe-inline'` (they cannot carry a
 * nonce); migrating them to listeners is tracked separately.
 *
 * 'unsafe-eval' stays until Alpine.js moves to its CSP build.
 *
 * Captcha providers (Turnstile, reCAPTCHA, hCaptcha) challenge iframe origins are merged
 * from the `captcha_urls` catalog in config/reference-data.php, so new providers only need
 * a catalog entry instead of a middleware change.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** Cached reference-data catalog — config does not change per request. */
    private static ?array $catalog = null;

    /** Settings holding operator-authored HTML that may pull remote scripts. */
    private const OPERATOR_SCRIPT_SETTINGS = [
        'custom_header_scripts',
        'custom_footer_scripts',
        'advertisement_code',
    ];

    public function __construct(
        private readonly ?SettingsRepositoryInterface $settings = null,
        private readonly ?CspNonce $nonce = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonce = $this->nonceValue();

        $response = $handler->handle($request);

        if (self::$catalog === null) {
            $loaded        = require __DIR__ . '/../../config/reference-data.php';
            self::$catalog = is_array($loaded) ? $loaded : [];
        }

        // Captcha challenge iframe origins come from the reference-data catalog —
        // e.g. Turnstile renders its challenge in an iframe on challenges.cloudflare.com.
        $catalog             = self::$catalog;
        $captchaFrameOrigins = array_values(array_unique(array_filter(
            array_column($catalog['captcha_urls'] ?? [], 'frame_src')
        )));

        // Media embed + payment SDK frame origins come from the media_embeds catalog.
        $mediaFrameOrigins = array_values(array_unique(array_filter(
            array_column($catalog['media_embeds'] ?? [], 'frame_src')
        )));

        $frameSources = array_merge(
            ["'self'"],
            $mediaFrameOrigins,
            $captchaFrameOrigins
        );

        // form-action allows 'self' plus the configured APP_URL origin so absolute
        // URLs produced by absolute_url() (e.g. checkout redirects) are never
        // blocked when the app is served from a different host than APP_URL.
        $formAction     = $this->buildFormActionSources($request);
        $connectSources = $this->buildConnectSources();
        $scriptSources  = $this->buildScriptSources($nonce);

        $csp = "default-src 'self'; "
            . 'script-src ' . implode(' ', $scriptSources) . '; '
            // Inline handlers (onclick=, onsubmit=, onchange= …) cannot carry a
            // nonce; they stay enabled here while <script> elements require one.
            . "script-src-attr 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline' https:; "
            . "img-src 'self' data: https: blob:; "
            . "manifest-src 'self'; "
            . "worker-src 'self'; "
            . "font-src 'self' data: https:; "
            . 'connect-src ' . implode(' ', $connectSources) . '; '
            . 'frame-src ' . implode(' ', $frameSources) . '; '
            . "frame-ancestors 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . 'form-action ' . implode(' ', $formAction) . ';';

        $response = $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Add HSTS header if request is served over HTTPS
        $uri = $request->getUri();
        if ($uri->getScheme() === 'https' || ($request->getHeaderLine('X-Forwarded-Proto') === 'https')) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $this->applyNonceToBody($response, $nonce);
    }

    /**
     * script-src sources: first-party scripts plus the request nonce.
     *
     * `https:` is only added when the operator authored raw script HTML in the
     * settings — those snippets (and anything they inject at runtime) cannot be
     * covered by a nonce, so removing their origin allowance would silently
     * break analytics/ad code. Self-hosted builds never need it: every <script>
     * tag in the templates carries the nonce.
     *
     * @return list<string>
     */
    private function buildScriptSources(string $nonce): array
    {
        $sources = ["'self'", "'nonce-" . $nonce . "'", "'unsafe-eval'"];

        // Captcha widgets and Stripe Elements fetch further scripts from their
        // own hosts after the nonced loader runs. List those exact origins
        // instead of falling back to a blanket `https:` scheme.
        $catalog = self::$catalog ?? [];
        foreach (['captcha_urls', 'payment_api'] as $group) {
            foreach ($catalog[$group] ?? [] as $provider) {
                if (!is_array($provider) || empty($provider['script_src'])) {
                    continue;
                }
                foreach (preg_split('/\s+/', (string) $provider['script_src']) ?: [] as $origin) {
                    $origin = trim($origin);
                    if ($origin !== '' && !in_array($origin, $sources, true)) {
                        $sources[] = $origin;
                    }
                }
            }
        }

        if ($this->hasOperatorAuthoredScripts()) {
            $sources[] = 'https:';
        }

        return $sources;
    }

    private function hasOperatorAuthoredScripts(): bool
    {
        if ($this->settings === null) {
            return false;
        }

        try {
            foreach (self::OPERATOR_SCRIPT_SETTINGS as $key) {
                if (trim((string) ($this->settings->get($key, '') ?? '')) !== '') {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function nonceValue(): string
    {
        return $this->nonce?->value() ?? base64_encode(random_bytes(16));
    }

    /**
     * Swap the render-time placeholder for this request's nonce.
     *
     * Cached HTML is stored with the placeholder intact, so the cache can serve
     * every guest while each response still carries a matching nonce.
     */
    private function applyNonceToBody(ResponseInterface $response, string $nonce): ResponseInterface
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = $response->getBody();
        if (!$body->isSeekable()) {
            return $response;
        }

        $body->rewind();
        $html = (string) $body;

        if (!str_contains($html, CspNonce::PLACEHOLDER)) {
            return $response;
        }

        $html   = str_replace(CspNonce::PLACEHOLDER, $nonce, $html);
        $stream = new Stream(fopen('php://temp', 'r+'));
        $stream->write($html);
        $stream->rewind();

        $response = $response->withBody($stream);

        if ($response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length', (string) strlen($html));
        }

        return $response;
    }

    /**
     * connect-src sources: 'self' and HTTPS by default. A configured
     * collaboration server is a WebSocket endpoint, which `https:` never
     * covers, so its exact origin is added while the feature is enabled.
     *
     * @return list<string>
     */
    private function buildConnectSources(): array
    {
        $sources = ["'self'", 'https:'];

        if ($this->settings === null) {
            return $sources;
        }

        try {
            if (($this->settings->get('collaboration_enabled', '0') ?? '0') !== '1') {
                return $sources;
            }
            $url = trim((string) ($this->settings->get('collaboration_url', '') ?? ''));
        } catch (\Throwable) {
            return $sources;
        }

        $parts = $url !== '' ? parse_url($url) : false;
        if (!is_array($parts) || empty($parts['host'])) {
            return $sources;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'ws'));
        $scheme = match ($scheme) {
            'http', 'ws'   => 'ws',
            'https', 'wss' => 'wss',
            default        => '',
        };
        if ($scheme === '') {
            return $sources;
        }

        $host      = (string) $parts['host'];
        $port      = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $sources[] = $scheme . '://' . $host . $port;

        // CSP host-sources do not reliably match IP addresses; keep a scheme
        // fallback for LAN/dev URLs (127.0.0.1 is exempt).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && $host !== '127.0.0.1') {
            $sources[] = $scheme . ':';
        }

        return array_values(array_unique($sources));
    }

    /**
     * form-action sources: 'self' plus the APP_URL origin and the current
     * request origin, so absolute URLs generated by absolute_url() (checkout
     * redirects, ajax base) are never blocked when the app is served from a
     * different host than APP_URL (e.g. LAN IP vs localhost during dev).
     *
     * External payment handoff targets (PayPal, Mollie, Checkout.com,
     * 2Checkout approve/checkout URLs) come from the `payment_api` catalog's
     * `checkout_src` entries — the shopper is 302-redirected there after
     * POST /checkout, and the browser enforces form-action on that hop.
     *
     * When APP_URL points at an IP address, its origin source is replaced by
     * the URL's scheme: CSP host-sources never match IP-address hosts (only
     * 127.0.0.1 is exempt), so an IP-based origin source would be inert and
     * could never allow the absolute form actions generated from APP_URL.
     *
     * @return list<string>
     */
    private function buildFormActionSources(ServerRequestInterface $request): array
    {
        $sources = ["'self'"];

        $appUrl  = (string) (env('APP_URL') ?? '');
        $appHost = $appUrl !== '' ? (string) (parse_url($appUrl, PHP_URL_HOST) ?? '') : '';
        if ($appHost !== '') {
            if (filter_var($appHost, FILTER_VALIDATE_IP) !== false && $appHost !== '127.0.0.1') {
                // IP-based APP_URL (LAN/dev setups): a host-source could never
                // match it, so fall back to allowing the URL's scheme.
                $scheme = strtolower((string) (parse_url($appUrl, PHP_URL_SCHEME) ?? ''));
                if ($scheme !== '' && !in_array($scheme . ':', $sources, true)) {
                    $sources[] = $scheme . ':';
                }
            } elseif (preg_match('~^https?://[^/]+~i', $appUrl, $m)) {
                $sources[] = $m[0];
            }
        }

        $uri     = $request->getUri();
        $current = $uri->getScheme() . '://' . $uri->getHost()
            . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
        if (!in_array($current, $sources, true)) {
            $sources[] = $current;
        }

        // Payment gateway handoff origins from the reference-data catalog —
        // e.g. PayPal approve URLs (www.paypal.com / www.sandbox.paypal.com).
        $catalog = self::$catalog ?? [];
        foreach ($catalog['payment_api'] ?? [] as $provider) {
            if (!is_array($provider) || empty($provider['checkout_src'])) {
                continue;
            }
            foreach (preg_split('/\s+/', (string) $provider['checkout_src']) ?: [] as $origin) {
                $origin = trim($origin);
                if ($origin !== '' && !in_array($origin, $sources, true)) {
                    $sources[] = $origin;
                }
            }
        }

        return array_values(array_unique($sources));
    }
}
