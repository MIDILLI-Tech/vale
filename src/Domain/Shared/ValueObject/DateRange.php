<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\ValueObject;

/**
 * Inclusive calendar-day range for analytics queries.
 *
 * The upper bound is stored exclusive (start of the day after $toDate)
 * so SQL can use `created_at >= from AND created_at < toExclusive`
 * without 23:59:59 hacks.
 */
final readonly class DateRange
{
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $toExclusive;

    public function __construct(
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
    ) {
        $from        = $fromDate->setTime(0, 0, 0);
        $toExclusive = $toDate->setTime(0, 0, 0)->modify('+1 day');

        if ($from >= $toExclusive) {
            throw new \InvalidArgumentException('DateRange start must not be after end.');
        }

        $this->from        = $from;
        $this->toExclusive = $toExclusive;
    }

    public function fromSql(): string
    {
        return $this->from->format('Y-m-d H:i:s');
    }

    public function toSql(): string
    {
        return $this->toExclusive->format('Y-m-d H:i:s');
    }

    public function dayCount(): int
    {
        return (int) $this->from->diff($this->toExclusive)->days;
    }
}
