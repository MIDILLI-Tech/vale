<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Security;

/**
 * Per-request Content-Security-Policy nonce.
 *
 * Templates emit {@see self::PLACEHOLDER} instead of the real value so the
 * full-page cache can store one HTML file for every guest request; the
 * security-headers middleware swaps the placeholder for the request's nonce
 * on the way out. A cached page therefore never carries a stale nonce.
 */
final class CspNonce
{
    /** Marker rendered into HTML; replaced right before the response is sent. */
    public const PLACEHOLDER = '__VL_CSP_NONCE__';

    private ?string $value = null;

    public function value(): string
    {
        return $this->value ??= base64_encode(random_bytes(16));
    }
}
