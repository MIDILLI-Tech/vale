<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Http;

use RuntimeException;

/**
 * Signals why SafeRemoteFetcher refused or failed to fetch a remote URL.
 * Callers map the reason to their own error handling (HTTP status, null result).
 */
final class RemoteFetchException extends RuntimeException
{
    public const REASON_INVALID_SCHEME     = 'invalid_scheme';
    public const REASON_PRIVATE_HOST       = 'private_host';
    public const REASON_INVALID_REDIRECT   = 'invalid_redirect';
    public const REASON_TOO_MANY_REDIRECTS = 'too_many_redirects';
    public const REASON_HTTP_ERROR         = 'http_error';
    public const REASON_CONNECTION_FAILED  = 'connection_failed';

    private function __construct(
        private readonly string $reason,
        string $message,
        private readonly ?int $upstreamStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidScheme(): self
    {
        return new self(self::REASON_INVALID_SCHEME, 'Only http and https downloads are allowed.');
    }

    public static function privateHost(): self
    {
        return new self(self::REASON_PRIVATE_HOST, 'Forbidden download URL.');
    }

    public static function invalidRedirect(): self
    {
        return new self(self::REASON_INVALID_REDIRECT, 'Invalid redirect location.');
    }

    public static function tooManyRedirects(): self
    {
        return new self(self::REASON_TOO_MANY_REDIRECTS, 'Too many redirects.');
    }

    public static function httpError(int $upstreamStatus): self
    {
        return new self(
            self::REASON_HTTP_ERROR,
            "Remote server responded with HTTP {$upstreamStatus}.",
            $upstreamStatus,
        );
    }

    public static function connectionFailed(): self
    {
        return new self(self::REASON_CONNECTION_FAILED, 'Could not connect to remote file.');
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getUpstreamStatus(): ?int
    {
        return $this->upstreamStatus;
    }
}
