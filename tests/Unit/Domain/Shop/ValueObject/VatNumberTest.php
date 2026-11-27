<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shop\ValueObject;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shop\ValueObject\VatNumber;

final class VatNumberTest extends TestCase
{
    public function testParsesPrefixedNumberAndNormalizes(): void
    {
        $vat = VatNumber::fromString(' de 123 456 789 ');

        self::assertNotNull($vat);
        self::assertSame('DE', $vat->country());
        self::assertSame('123456789', $vat->number());
        self::assertSame('DE123456789', $vat->formatted());
        self::assertTrue($vat->isEu());
    }

    public function testUsesBillingCountryWhenPrefixIsMissing(): void
    {
        $vat = VatNumber::fromString('123456789', 'DE');

        self::assertNotNull($vat);
        self::assertSame('DE123456789', $vat->formatted());
    }

    public function testAcceptsFrenchAndDutchFormats(): void
    {
        self::assertSame('FR', VatNumber::fromString('FRAB123456789')?->country());
        self::assertSame('NL', VatNumber::fromString('NL123456789B01')?->country());
    }

    public function testRejectsMalformedEuNumbers(): void
    {
        self::assertNull(VatNumber::fromString('DE12345', 'DE'));
        self::assertNull(VatNumber::fromString('FR12', 'FR'));
        self::assertNull(VatNumber::fromString(''));
    }

    public function testNonEuNumbersAreStoredButNotEu(): void
    {
        $vat = VatNumber::fromString('GB123456789');

        self::assertNotNull($vat);
        self::assertFalse($vat->isEu());
    }

    public function testEuCountryMapExcludesEeaOnlyStates(): void
    {
        self::assertTrue(VatNumber::isEuCountry('DE'));
        self::assertTrue(VatNumber::isEuCountry('gr'));
        self::assertFalse(VatNumber::isEuCountry('NO'));
        self::assertFalse(VatNumber::isEuCountry('GB'));
    }
}
