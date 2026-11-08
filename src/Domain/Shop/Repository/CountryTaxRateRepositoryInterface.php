<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Repository;

use ValeApp\Domain\Shop\Entity\CountryTaxRate;

interface CountryTaxRateRepositoryInterface
{
    /** @param string[] $codes */
    public function findByCountryCodes(array $codes): array;

    public function findByCountry(string $countryCode): ?CountryTaxRate;

    /** @return CountryTaxRate[] */
    public function findAll(): array;

    public function findFallback(): ?CountryTaxRate;

    public function save(CountryTaxRate $rate): CountryTaxRate;

    public function delete(int $id): void;

    /** @param int[] $keepIds */
    public function deleteExcept(array $keepIds): void;
}
