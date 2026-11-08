<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class CountryTaxRate extends BaseEntity
{
    private string $countryCode;
    private string $regime      = 'normal_vat';
    private ?float $rate        = null;
    private ?string $zeroReason = null;
    private bool $isFallback    = false;

    public function __construct(string $countryCode)
    {
        $this->countryCode = $countryCode;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }
    public function getRegime(): string
    {
        return $this->regime;
    }
    public function getRate(): ?float
    {
        return $this->rate;
    }
    public function getZeroReason(): ?string
    {
        return $this->zeroReason;
    }
    public function isFallback(): bool
    {
        return $this->isFallback;
    }

    public function setCountryCode(string $code): void
    {
        $this->countryCode = $code;
    }
    public function setRegime(string $regime): void
    {
        $this->regime = $regime;
    }
    public function setRate(?float $rate): void
    {
        $this->rate = $rate;
    }
    public function setZeroReason(?string $reason): void
    {
        $this->zeroReason = $reason;
    }
    public function setIsFallback(bool $v): void
    {
        $this->isFallback = $v;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $entity             = new self($row['country_code']);
        $entity->id         = isset($row['id']) ? (int) $row['id'] : null;
        $entity->regime     = (string) ($row['regime'] ?? 'normal_vat');
        $entity->rate       = isset($row['rate']) && $row['rate'] !== null ? (float) $row['rate'] : null;
        $entity->zeroReason = $row['zero_reason'] ?? null;
        $entity->isFallback = (bool) ($row['is_fallback'] ?? false);
        if (!empty($row['created_at'])) {
            $entity->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        return $entity;
    }
}
