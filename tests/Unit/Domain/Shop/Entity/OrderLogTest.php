<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shop\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shop\Entity\OrderLog;

final class OrderLogTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $log = new OrderLog(1, 'status_changed');

        self::assertSame(1, $log->getOrderId());
        self::assertSame('status_changed', $log->getEvent());
        self::assertNull($log->getNote());
        self::assertInstanceOf(\DateTimeImmutable::class, $log->getCreatedAt());
        self::assertSame('system', $log->getType());
        self::assertNull($log->getUserId());
        self::assertNull($log->getAddedBy());
        self::assertNull($log->getId());
    }

    public function testConstructorWithOptions(): void
    {
        $dt  = new \DateTimeImmutable('2024-01-01');
        $log = new OrderLog(5, 'paid', 'Payment received', $dt, 'admin', 10, 'Admin User');

        self::assertSame(5, $log->getOrderId());
        self::assertSame('paid', $log->getEvent());
        self::assertSame('Payment received', $log->getNote());
        self::assertSame($dt, $log->getCreatedAt());
        self::assertSame('admin', $log->getType());
        self::assertSame(10, $log->getUserId());
        self::assertSame('Admin User', $log->getAddedBy());
    }

    public function testFromRow(): void
    {
        $log = OrderLog::fromRow([
            'id'         => '20',
            'order_id'   => '7',
            'event'      => 'shipped',
            'note'       => 'Package sent',
            'created_at' => '2024-06-15 14:00:00',
            'type'       => 'admin',
            'user_id'    => '3',
            'added_by'   => 'Admin',
        ]);

        self::assertSame(20, $log->getId());
        self::assertSame(7, $log->getOrderId());
        self::assertSame('shipped', $log->getEvent());
        self::assertSame('Package sent', $log->getNote());
        self::assertSame('admin', $log->getType());
        self::assertSame(3, $log->getUserId());
        self::assertSame('Admin', $log->getAddedBy());
        self::assertInstanceOf(\DateTimeImmutable::class, $log->getCreatedAt());
    }

    public function testFromRowDefaults(): void
    {
        $log = OrderLog::fromRow([
            'id'       => '1',
            'order_id' => '1',
            'event'    => 'created',
        ]);

        self::assertNull($log->getNote());
        self::assertNull($log->getUserId());
        self::assertNull($log->getAddedBy());
        self::assertSame('system', $log->getType());
        self::assertInstanceOf(\DateTimeImmutable::class, $log->getCreatedAt());
    }
}
