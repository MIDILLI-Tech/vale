<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shop\Port\VatNumberValidatorInterface;
use ValeApp\Domain\Shop\ValueObject\VatNumber;
use ValeApp\Domain\Shop\ValueObject\VatValidation;

/**
 * B2B checkout rules: validate the buyer's VAT number, then decide whether the
 * order qualifies for the intra-EU reverse charge (VAT not charged).
 */
final class B2bCheckoutService
{
    public function __construct(
        private readonly VatNumberValidatorInterface $validator,
        private readonly ReverseChargePolicy $policy,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function isEnabled(): bool
    {
        return in_array(
            (string) ($this->settings->get('shop_customer_type', 'b2c') ?? 'b2c'),
            ['b2b', 'both'],
            true,
        );
    }

    /**
     * @return array{vat_number: ?VatNumber, validation: ?VatValidation, error: ?string, warning: ?string}
     */
    public function validate(string $input, ?string $billingCountry): array
    {
        $vatNumber = VatNumber::fromString($input, $billingCountry);
        if ($vatNumber === null) {
            return [
                'vat_number' => null,
                'validation' => null,
                'error'      => __('Enter a valid VAT number, including the country code.'),
                'warning'    => null,
            ];
        }

        if (!$vatNumber->isEu()) {
            return [
                'vat_number' => $vatNumber,
                'validation' => null,
                'error'      => null,
                'warning'    => __('VAT numbers outside the EU cannot be validated online, so VAT is charged.'),
            ];
        }

        if ((string) ($this->settings->get('b2b_vat_validation', '1') ?? '1') !== '1') {
            return [
                'vat_number' => $vatNumber,
                'validation' => null,
                'error'      => null,
                'warning'    => __('VAT validation is disabled, so VAT is charged for this order.'),
            ];
        }

        $validation = $this->validator->validate($vatNumber);
        if ($validation->isInvalid()) {
            return [
                'vat_number' => $vatNumber,
                'validation' => $validation,
                'error'      => __('This VAT number is not valid according to VIES. Correct it or untick the business purchase option.'),
                'warning'    => null,
            ];
        }

        if ($validation->isUnavailable()) {
            return [
                'vat_number' => $vatNumber,
                'validation' => $validation,
                'error'      => null,
                'warning'    => __('VIES could not be reached, so VAT is charged for this order. Try again later.'),
            ];
        }

        return [
            'vat_number' => $vatNumber,
            'validation' => $validation,
            'error'      => null,
            'warning'    => null,
        ];
    }

    /**
     * VIES only reveals the registered name for some member states. When it
     * does, compare it with what the customer typed so checkout can warn
     * about a likely typo without blocking a valid VAT number.
     */
    public function companyNameMismatch(?VatValidation $validation, string $companyName): bool
    {
        if ($validation === null || !$validation->isValid()) {
            return false;
        }

        $registered = $this->normalizeCompanyName((string) $validation->traderName());
        $entered    = $this->normalizeCompanyName($companyName);

        if ($registered === '' || $entered === '') {
            return false;
        }

        if ($registered === $entered) {
            return false;
        }

        if (strlen($registered) >= 3 && str_contains($entered, $registered)) {
            return false;
        }

        if (strlen($entered) >= 3 && str_contains($registered, $entered)) {
            return false;
        }

        return true;
    }

    public function reverseChargeApplies(?VatValidation $validation, string $customerCountry): bool
    {
        if ($validation === null) {
            return false;
        }

        return $this->policy->applies(
            $validation,
            (string) ($this->settings->get('shop_country', '') ?? ''),
            $customerCountry,
        );
    }

    private function normalizeCompanyName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^(?:-+|n\/?a)$/i', $name) === 1) {
            return '';
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (is_string($ascii)) {
            $name = $ascii;
        }

        $name   = strtolower($name);
        $name   = str_replace(['.', ','], '', $name);
        $name   = str_replace('&', ' and ', $name);
        $name   = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';
        $tokens = preg_split('/\s+/', trim($name)) ?: [];

        $legalSuffixes = [
            'gmbh', 'mbh', 'ag', 'kg', 'ohg', 'ug', 'haftungsbeschrankt',
            'ltd', 'limited', 'llc', 'inc', 'incorporated', 'corp', 'corporation',
            'co', 'company', 'sa', 'sas', 'sarl', 'srl', 'spa', 'bv', 'nv',
            'ab', 'as', 'asa', 'oy', 'oyj', 'aps', 'plc', 'pte', 'sl', 'sro',
            'spol', 'zoo',
        ];

        while ($tokens !== [] && in_array((string) end($tokens), $legalSuffixes, true)) {
            array_pop($tokens);
        }

        return implode(' ', $tokens);
    }
}
