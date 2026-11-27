<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\ValueObject;

final class VatNumber
{
    /** EU member states (VIES coverage). EEA-only states are excluded. */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'EL',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI',
        'ES', 'SE',
    ];

    private const FORMATS = [
        'AT' => '/^U\d{8}$/',
        'BE' => '/^\d{9,10}$/',
        'BG' => '/^\d{9,10}$/',
        'HR' => '/^\d{11}$/',
        'CY' => '/^\d{8}[A-Z]$/',
        'CZ' => '/^\d{8,10}$/',
        'DK' => '/^\d{8}$/',
        'EE' => '/^\d{9}$/',
        'FI' => '/^\d{8}$/',
        'FR' => '/^[A-Z0-9]{2}\d{9}$/',
        'DE' => '/^\d{9}$/',
        'GR' => '/^\d{9}$/',
        'EL' => '/^\d{9}$/',
        'HU' => '/^\d{8}$/',
        'IE' => '/^(\d{7}[A-Z]{1,2}|\d[A-Z+*]\d{5}[A-Z])$/',
        'IT' => '/^\d{11}$/',
        'LV' => '/^\d{11}$/',
        'LT' => '/^(\d{9}|\d{12})$/',
        'LU' => '/^\d{8}$/',
        'MT' => '/^\d{8}$/',
        'NL' => '/^\d{9}B\d{2}$/',
        'PL' => '/^\d{10}$/',
        'PT' => '/^\d{9}$/',
        'RO' => '/^\d{2,10}$/',
        'SK' => '/^\d{10}$/',
        'SI' => '/^\d{8}$/',
        'ES' => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
        'SE' => '/^\d{12}$/',
    ];

    private function __construct(
        private readonly string $country,
        private readonly string $number,
    ) {
    }

    /**
     * Accepts "DE123456789", "de 123 456 789" or a number without a prefix when
     * the billing country is known.
     */
    public static function fromString(string $input, ?string $defaultCountry = null): ?self
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
        if ($clean === '') {
            return null;
        }

        if (preg_match('/^([A-Z]{2})([A-Z0-9]{2,14})$/', $clean, $matches) === 1) {
            $candidate = new self($matches[1], $matches[2]);
            if ($candidate->isWellFormed()) {
                return $candidate;
            }
        }

        $defaultCountry = strtoupper(trim((string) $defaultCountry));
        if ($defaultCountry !== '' && preg_match('/^[A-Z]{2}$/', $defaultCountry) === 1) {
            $candidate = new self($defaultCountry, $clean);
            if ($candidate->isWellFormed()) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function euCountries(): array
    {
        return self::EU_COUNTRIES;
    }

    public static function isEuCountry(string $country): bool
    {
        $country = strtoupper(trim($country));

        return in_array($country === 'GR' ? 'EL' : $country, self::EU_COUNTRIES, true);
    }

    public function country(): string
    {
        return $this->country;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function isEu(): bool
    {
        return self::isEuCountry($this->country);
    }

    public function formatted(): string
    {
        return $this->country . $this->number;
    }

    public static function normalizeCountry(string $country): string
    {
        $country = strtoupper(trim($country));

        return $country === 'GR' ? 'EL' : $country;
    }

    private function isWellFormed(): bool
    {
        $format = self::FORMATS[self::normalizeCountry($this->country)] ?? null;

        if ($format !== null) {
            return preg_match($format, $this->number) === 1;
        }

        // Non-EU numbers are stored for the invoice but never VIES-validated.
        return preg_match('/^[A-Z0-9]{2,14}$/', $this->number) === 1;
    }
}
