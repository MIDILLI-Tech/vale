<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

/**
 * Resolves captcha provider URLs (verify + widget script) from the
 * `captcha_urls` catalog in config/reference-data.php.
 *
 * Hardcoded fallbacks keep verification working when the catalog entry
 * is missing; the catalog is the single source of truth.
 */
final class CaptchaUrls
{
    /** @var array<string, array{verify_url?: string, script_url?: string}> */
    private array $catalog;

    private static ?self $fileInstance = null;

    /** @param array<string, mixed> $catalog */
    public function __construct(array $catalog = [])
    {
        /** @var array<string, array{verify_url?: string, script_url?: string}> $filtered */
        $filtered      = is_array($catalog) ? $catalog : [];
        $this->catalog = $filtered;
    }

    public static function fromFile(): self
    {
        if (self::$fileInstance === null) {
            $data = require dirname(__DIR__, 3) . '/config/reference-data.php';

            self::$fileInstance = new self(is_array($data['captcha_urls'] ?? null) ? $data['captcha_urls'] : []);
        }

        return self::$fileInstance;
    }

    public function verifyUrl(string $provider): string
    {
        $url = $this->catalog[$provider]['verify_url'] ?? '';

        if (!is_string($url) || $url === '') {
            throw new \RuntimeException(
                "Missing captcha verify_url for provider '{$provider}' in config/reference-data.php (captcha_urls)."
            );
        }

        return $url;
    }

    public function scriptUrl(string $provider): string
    {
        $url = $this->catalog[$provider]['script_url'] ?? '';

        if (!is_string($url) || $url === '') {
            throw new \RuntimeException(
                "Missing captcha script_url for provider '{$provider}' in config/reference-data.php (captcha_urls)."
            );
        }

        return $url;
    }
}
