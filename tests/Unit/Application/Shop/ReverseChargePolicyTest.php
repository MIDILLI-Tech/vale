<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\ReverseChargePolicy;
use ValeApp\Domain\Shop\ValueObject\VatValidation;

final class ReverseChargePolicyTest extends TestCase
{
    private ReverseChargePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ReverseChargePolicy();
    }

    public function testAppliesToValidatedCrossBorderEuSale(): void
    {
        self::assertTrue($this->policy->applies(VatValidation::valid(), 'DE', 'FR'));
    }

    public function testDoesNotApplyToDomesticSale(): void
    {
        self::assertFalse($this->policy->applies(VatValidation::valid(), 'DE', 'DE'));
    }

    public function testDoesNotApplyWhenEitherSideIsOutsideTheEu(): void
    {
        self::assertFalse($this->policy->applies(VatValidation::valid(), 'DE', 'GB'));
        self::assertFalse($this->policy->applies(VatValidation::valid(), 'US', 'DE'));
    }

    public function testDoesNotApplyWithoutValidValidation(): void
    {
        self::assertFalse($this->policy->applies(VatValidation::invalid(), 'DE', 'FR'));
        self::assertFalse($this->policy->applies(VatValidation::unavailable(), 'DE', 'FR'));
    }
}
