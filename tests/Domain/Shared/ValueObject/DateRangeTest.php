<?php

declare(strict_types=1);

namespace ValeApp\Tests\Domain\Shared\ValueObject;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\ValueObject\DateRange;

final class DateRangeTest extends TestCase
{
    public function testNormalizesToCalendarDaysWithExclusiveEnd(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-08-01 15:30:00'),
            new \DateTimeImmutable('2026-08-03 09:00:00'),
        );

        self::assertSame('2026-08-01 00:00:00', $range->fromSql());
        self::assertSame('2026-08-04 00:00:00', $range->toSql());
        self::assertSame(3, $range->dayCount());
    }

    public function testSingleDayRange(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-01'),
        );

        self::assertSame('2026-08-01 00:00:00', $range->fromSql());
        self::assertSame('2026-08-02 00:00:00', $range->toSql());
        self::assertSame(1, $range->dayCount());
    }

    public function testStartAfterEndThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DateRange(
            new \DateTimeImmutable('2026-08-05'),
            new \DateTimeImmutable('2026-08-01'),
        );
    }
}
