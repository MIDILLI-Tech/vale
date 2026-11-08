<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shop\Entity\CountryTaxRate;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;

final class DbalCountryTaxRateRepository implements CountryTaxRateRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findByCountryCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $qb = $this->db->createQueryBuilder();
        $qb->select('*')
            ->from(VL_DB_PREFIX . 'country_tax_rates')
            ->where('country_code IN (:codes)')
            ->setParameter('codes', $codes, ArrayParameterType::STRING);
        $rows = $qb->fetchAllAssociative();
        return array_map(fn (array $row) => CountryTaxRate::fromRow($row), $rows);
    }

    public function findByCountry(string $countryCode): ?CountryTaxRate
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'country_tax_rates' . ' WHERE country_code = ?',
            [$countryCode],
            [ParameterType::STRING],
        );
        return $row !== false ? CountryTaxRate::fromRow($row) : null;
    }

    /** @return CountryTaxRate[] */
    public function findAll(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'country_tax_rates' . ' ORDER BY is_fallback ASC, country_code ASC');
        return array_map(fn (array $row) => CountryTaxRate::fromRow($row), $rows);
    }

    public function findFallback(): ?CountryTaxRate
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'country_tax_rates' . ' WHERE is_fallback = TRUE LIMIT 1',
        );
        return $row !== false ? CountryTaxRate::fromRow($row) : null;
    }

    public function save(CountryTaxRate $rate): CountryTaxRate
    {
        $data = [
            'country_code' => $rate->getCountryCode(),
            'regime'       => $rate->getRegime(),
            'rate'         => $rate->getRate(),
            'zero_reason'  => $rate->getZeroReason(),
            'is_fallback'  => $rate->isFallback() ? 1 : 0,
        ];

        if ($rate->getId() === null) {
            $data['created_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->insert(VL_DB_PREFIX . 'country_tax_rates', $data);
            $rate->setId((int) $this->db->lastInsertId());
        } else {
            $data['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->update(VL_DB_PREFIX . 'country_tax_rates', $data, ['id' => $rate->getId()]);
        }

        return $rate;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'country_tax_rates', ['id' => $id]);
    }

    public function deleteExcept(array $keepIds): void
    {
        if ($keepIds === []) {
            $this->db->executeStatement('DELETE FROM ' . VL_DB_PREFIX . 'country_tax_rates');
            return;
        }
        $qb = $this->db->createQueryBuilder();
        $qb->delete(VL_DB_PREFIX . 'country_tax_rates')
            ->where($qb->expr()->notIn('id', ':keep'))
            ->setParameter('keep', $keepIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }
}
