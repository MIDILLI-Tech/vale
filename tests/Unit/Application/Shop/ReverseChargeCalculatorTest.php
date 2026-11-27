<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\ReverseChargeCalculator;

final class ReverseChargeCalculatorTest extends TestCase
{
    public function testExtractsVatAndKeepsTotalsConsistent(): void
    {
        $items = [
            ['price' => 119.00, 'qty' => 1],
            ['price' => 107.00, 'qty' => 1],
        ];
        $taxBreakdown = [
            'total'    => 28.24,
            'shipping' => 1.90,
            'lines'    => [
                0 => ['rate' => 19.0, 'amount' => 19.00],
                1 => ['rate' => 7.0, 'amount' => 7.00],
            ],
        ];

        $result = (new ReverseChargeCalculator())->net($items, 11.90, 10.00, $taxBreakdown);

        self::assertSame(200.0, $result['subtotal']);
        self::assertSame(10.0, $result['shipping']);
        self::assertSame(0.0, $result['tax']);
        self::assertEqualsWithDelta(
            round($result['subtotal'] + $result['shipping'] - $result['discount'], 2),
            $result['total'],
            0.001,
        );
        self::assertSame(100.0, $result['items'][0]['price']);
        self::assertSame(100.0, $result['items'][1]['price']);
        self::assertGreaterThan(0.0, $result['vat_exempted']);
        self::assertEqualsWithDelta(227.90 - $result['total'], $result['vat_exempted'], 0.01);
    }

    public function testZeroRateLinesStayUntouched(): void
    {
        $result = (new ReverseChargeCalculator())->net(
            [['price' => 50.0, 'qty' => 2]],
            0.0,
            0.0,
            ['total' => 0.0, 'shipping' => 0.0, 'lines' => []],
        );

        self::assertSame(100.0, $result['subtotal']);
        self::assertSame(100.0, $result['total']);
        self::assertSame(0.0, $result['vat_exempted']);
    }

    public function testDiscountLinesAreNettedPerLine(): void
    {
        $result = (new ReverseChargeCalculator())->net(
            [['price' => 119.0, 'qty' => 1]],
            0.0,
            19.0,
            ['total' => 19.0, 'shipping' => 0.0, 'lines' => [0 => ['rate' => 19.0, 'amount' => 19.0]]],
            [0 => 19.0],
        );

        self::assertSame(100.0, $result['subtotal']);
        self::assertSame(15.97, $result['discount']);
        self::assertSame(84.03, $result['total']);
    }
}
