<?php

declare(strict_types=1);

namespace ValeApp\Application\Consent;

/**
 * Decides which captcha a visitor actually gets.
 *
 * When the operator gates captcha behind consent, providers that store cookies
 * (hCaptcha, reCAPTCHA, Turnstile) only render once the visitor accepted the
 * functional category. Everybody else — including visitors who reject — gets
 * the local math challenge (plus the honeypot field), so a form never becomes
 * unusable and no provider script loads without a choice.
 *
 * The gate is bound once per request by ConsentMiddleware; render and
 * validation both read it through ::effectiveType(), so they can never
 * disagree about which challenge was shown.
 */
final class CaptchaConsentGate
{
    private const PROVIDERS = ['hcaptcha', 'recaptcha', 'turnstile'];

    /** null = gating is off, the configured provider is used as is. */
    private static ?bool $providerAllowed = null;

    public static function configure(?bool $providerAllowed): void
    {
        self::$providerAllowed = $providerAllowed;
    }

    public static function isGating(): bool
    {
        return self::$providerAllowed !== null;
    }

    public static function providerAllowed(): bool
    {
        return self::$providerAllowed !== false;
    }

    /**
     * Captcha type to render and validate for the configured one.
     */
    public static function effectiveType(string $configured): string
    {
        $type = strtolower(trim($configured));

        if (self::$providerAllowed === false && in_array($type, self::PROVIDERS, true)) {
            return 'math';
        }

        return $type;
    }

    /**
     * True when the visitor sees the local fallback instead of the configured
     * provider — used to add the honeypot field and to word the form hint.
     */
    public static function isFallback(string $configured): bool
    {
        $type = strtolower(trim($configured));

        return in_array($type, self::PROVIDERS, true) && self::effectiveType($type) !== $type;
    }
}
