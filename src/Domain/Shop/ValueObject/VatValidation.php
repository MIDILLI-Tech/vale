<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\ValueObject;

final class VatValidation
{
    public const VALID       = 'valid';
    public const INVALID     = 'invalid';
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        private readonly string $status,
        private readonly ?string $traderName = null,
    ) {
    }

    public static function valid(?string $traderName = null): self
    {
        return new self(self::VALID, $traderName);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isValid(): bool
    {
        return $this->status === self::VALID;
    }

    public function isInvalid(): bool
    {
        return $this->status === self::INVALID;
    }

    public function isUnavailable(): bool
    {
        return $this->status === self::UNAVAILABLE;
    }

    public function traderName(): ?string
    {
        return $this->traderName;
    }
}
