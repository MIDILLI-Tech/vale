<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Payment;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Payment\Money;

final class MoneyTest extends TestCase
{
    public function testToMinorUnitsMultipliesDecimalCurrencies(): void
    {
        self::assertSame(1500, Money::toMinorUnits(15.0, 'USD'));
        self::assertSame(1500, Money::toMinorUnits(15.0, 'eur'));
        self::assertSame(1235, Money::toMinorUnits(12.345, 'TRY'));
    }

    public function testDecimalHelpersPreferBcMathWhenAvailable(): void
    {
        if (!Money::usesBcMath()) {
            self::markTestSkipped('ext-bcmath not loaded.');
        }
        self::assertSame('0.300000', Money::add('0.1', '0.2'));
        self::assertSame('0.020000', Money::mul('0.1', '0.2'));
        self::assertSame('12.35', Money::round2('12.345'));
        self::assertSame('-12.35', Money::round2('-12.345'));
    }

    public function testClassicFloatTrapIsAvoided(): void
    {
        // 0.1 + 0.2 !== 0.3 in binary floating point; decimal math is exact.
        self::assertSame('0.30', Money::round2(Money::add('0.1', '0.2')));
        self::assertSame(30, Money::toMinorUnits(0.3, 'USD'));
    }

    public function testMultiLineVatKeepsPerLineRounding(): void
    {
        // 3 lines at 19% VAT: per-line tax rounds to cents, total 9.59.
        // (Exact per-line/total values are asserted to lock the rounding.)
        $lines   = ['19.99', '29.99', '9.99'];
        $total   = '0';
        $perLine = [];
        foreach ($lines as $gross) {
            $net       = Money::div($gross, Money::add('1', Money::div('19', '100')));
            $tax       = Money::sub($gross, $net);
            $perLine[] = Money::round2($tax);
            $total     = Money::add($total, $tax);
        }
        self::assertSame(['3.19', '4.79', '1.60'], $perLine);
        self::assertSame('9.58', Money::round2($total));
        self::assertSame(958, Money::toMinorUnits((float) Money::round2($total), 'EUR'));
    }

    public function testDecimalHelpersFallBackWithoutBcMath(): void
    {
        // Helpers accept the same inputs and return numeric strings either way.
        self::assertSame('12.35', Money::round2(12.345));
        self::assertSame(0, Money::comp('1.00', '1.0'));
        self::assertSame(1, Money::comp('1.01', '1.0'));
        self::assertSame('0', Money::div('1', '0'));
        self::assertSame(12.34, Money::toFloat('12.34'));
    }

    public function testToMinorUnitsSkipsMultiplicationForZeroDecimal(): void
    {
        self::assertSame(1500, Money::toMinorUnits(1500.0, 'JPY'));
        self::assertSame(99000, Money::toMinorUnits(99000.4, 'KRW'));
        self::assertSame(500, Money::toMinorUnits(500.4, 'ISK'));
        self::assertSame(501, Money::toMinorUnits(500.6, 'ISK'));
    }

    public function testFormatForApiUsesTwoDecimalsForDecimalCurrencies(): void
    {
        self::assertSame('15.00', Money::formatForApi(15.0, 'USD'));
        self::assertSame('12.35', Money::formatForApi(12.345, 'EUR'));
    }

    public function testFormatForApiOmitsDecimalsForZeroDecimalCurrencies(): void
    {
        self::assertSame('1500', Money::formatForApi(1500.0, 'JPY'));
        self::assertSame('1501', Money::formatForApi(1500.6, 'JPY'));
    }

    public function testFormatForApiClampsNegativeAmountsToZero(): void
    {
        self::assertSame('0.00', Money::formatForApi(-5.0, 'USD'));
        self::assertSame('0', Money::formatForApi(-5.0, 'JPY'));
    }
}
