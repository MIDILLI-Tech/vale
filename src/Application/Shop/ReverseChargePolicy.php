<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Domain\Shop\ValueObject\VatNumber;
use ValeApp\Domain\Shop\ValueObject\VatValidation;

/**
 * Reverse charge applies to a validated VAT number on a cross-border supply
 * between two EU member states. Domestic B2B sales keep the local VAT.
 */
final class ReverseChargePolicy
{
    public function applies(VatValidation $validation, string $shopCountry, string $customerCountry): bool
    {
        $shopCountry     = VatNumber::normalizeCountry($shopCountry);
        $customerCountry = VatNumber::normalizeCountry($customerCountry);

        return $validation->isValid()
            && VatNumber::isEuCountry($shopCountry)
            && VatNumber::isEuCountry($customerCountry)
            && $shopCountry !== $customerCountry;
    }
}
