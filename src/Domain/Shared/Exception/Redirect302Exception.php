<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\Exception;

final class Redirect302Exception extends DomainException
{
    public function __construct(
        private readonly string $url,
        string $message = 'HTTP 302 Redirect',
        int $code = 302,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
