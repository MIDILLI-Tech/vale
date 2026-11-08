<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shop\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shop\Entity\CountryTaxRate;

final class CountryTaxRateTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $rate = new CountryTaxRate('TR');

        self::assertSame('TR', $rate->getCountryCode());
        self::assertSame('normal_vat', $rate->getRegime());
        self::assertNull($rate->getRate());
        self::assertNull($rate->getZeroReason());
        self::assertFalse($rate->isFallback());
    }

    public function testSetters(): void
    {
        $rate = new CountryTaxRate('DE');

        $rate->setCountryCode('AT');
        $rate->setRegime('reduced_vat');
        $rate->setRate(10.0);
        $rate->setZeroReason('Exempt');
        $rate->setIsFallback(true);

        self::assertSame('AT', $rate->getCountryCode());
        self::assertSame('reduced_vat', $rate->getRegime());
        self::assertSame(10.0, $rate->getRate());
        self::assertSame('Exempt', $rate->getZeroReason());
        self::assertTrue($rate->isFallback());
    }

    public function testFromRow(): void
    {
        $rate = CountryTaxRate::fromRow([
            'id'           => '5',
            'country_code' => 'TR',
            'regime'       => 'reduced_vat',
            'rate'         => '20.0',
            'zero_reason'  => null,
            'is_fallback'  => '1',
            'created_at'   => '2024-01-01 00:00:00',
        ]);

        self::assertSame(5, $rate->getId());
        self::assertSame('TR', $rate->getCountryCode());
        self::assertSame('reduced_vat', $rate->getRegime());
        self::assertSame(20.0, $rate->getRate());
        self::assertNull($rate->getZeroReason());
        self::assertTrue($rate->isFallback());
        self::assertInstanceOf(\DateTimeImmutable::class, $rate->getCreatedAt());
    }

    public function testFromRowNullRate(): void
    {
        $rate = CountryTaxRate::fromRow([
            'id'           => '1',
            'country_code' => 'US',
            'rate'         => null,
            'zero_reason'  => 'No VAT',
        ]);

        self::assertNull($rate->getRate());
        self::assertSame('No VAT', $rate->getZeroReason());
    }
}
