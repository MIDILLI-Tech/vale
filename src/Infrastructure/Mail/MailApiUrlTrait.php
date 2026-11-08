<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Mail;

/**
 * Resolves the mail driver's API base URL from the `mail_drivers` catalog in
 * config/reference-data.php. A missing entry is a configuration error — we
 * fail fast instead of hardcoding a fallback, so reference-data.php stays the
 * single source of truth for every provider endpoint.
 */
trait MailApiUrlTrait
{
    private function catalogApiUrl(): string
    {
        $short = (new \ReflectionClass(static::class))->getShortName();
        $slug  = strtolower(str_replace('Adapter', '', $short));

        $data = require dirname(__DIR__, 3) . '/config/reference-data.php';
        $url  = $data['mail_drivers'][$slug]['api_url'] ?? '';

        if (!is_string($url) || $url === '') {
            throw new \RuntimeException(
                "Missing api_url for mail driver '{$slug}' in config/reference-data.php (mail_drivers)."
            );
        }

        return $url;
    }
}
