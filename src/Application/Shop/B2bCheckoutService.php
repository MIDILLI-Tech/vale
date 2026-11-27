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
}
