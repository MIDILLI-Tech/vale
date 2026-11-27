<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\InvoiceVatBreakdown;
use ValeApp\Domain\Shop\Entity\Order;

final class InvoiceVatBreakdownTest extends TestCase
{
    public function testSplitsItemsByRate(): void
    {
        $order = new Order('VL-2026-0100', ['email' => 'buyer@example.com']);
        $order->setItems([
            ['price' => 119.0, 'qty' => 1, 'tax_rate' => 19.0, 'tax_amount' => 19.0],
            ['price' => 107.0, 'qty' => 1, 'tax_rate' => 7.0, 'tax_amount' => 7.0],
        ]);
        $order->setTax(26.0);

        $rows = (new InvoiceVatBreakdown())->build($order, 19.0);

        self::assertCount(2, $rows);
        self::assertSame(19.0, $rows[0]['rate']);
        self::assertSame(100.0, $rows[0]['net']);
        self::assertSame(19.0, $rows[0]['tax']);
        self::assertSame(7.0, $rows[1]['rate']);
        self::assertSame(100.0, $rows[1]['net']);
        self::assertSame(7.0, $rows[1]['tax']);
    }

    public function testAddsShippingVatToTheFallbackRate(): void
    {
        $order = new Order('VL-2026-0101', ['email' => 'buyer@example.com']);
        $order->setItems([
            ['price' => 119.0, 'qty' => 1, 'tax_rate' => 19.0, 'tax_amount' => 19.0],
        ]);
        $order->setShipping(11.9);
        $order->setTax(20.9);

        $rows = (new InvoiceVatBreakdown())->build($order, 19.0);

        self::assertCount(1, $rows);
        self::assertSame(19.0, $rows[0]['rate']);
        self::assertSame(110.0, $rows[0]['net']);
        self::assertSame(20.9, $rows[0]['tax']);
    }

    public function testComputesTaxFromGrossWhenNoStoredAmount(): void
    {
        $order = new Order('VL-2026-0102', ['email' => 'buyer@example.com']);
        $order->setItems([
            ['price' => 119.0, 'qty' => 1, 'tax_rate' => 19.0],
        ]);
        $order->setTax(19.0);

        $rows = (new InvoiceVatBreakdown())->build($order, 19.0);

        self::assertSame(100.0, $rows[0]['net']);
        self::assertSame(19.0, $rows[0]['tax']);
    }

    public function testZeroRatedOrderProducesZeroVat(): void
    {
        $order = new Order('VL-2026-0103', ['email' => 'buyer@example.com']);
        $order->setItems([
            ['price' => 50.0, 'qty' => 2, 'tax_rate' => 0.0, 'tax_amount' => 0.0],
        ]);
        $order->setTax(0.0);

        $rows = (new InvoiceVatBreakdown())->build($order, 0.0);

        self::assertCount(1, $rows);
        self::assertSame(0.0, $rows[0]['rate']);
        self::assertSame(100.0, $rows[0]['net']);
        self::assertSame(0.0, $rows[0]['tax']);
    }
}
