<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop;

/**
 * Canonical order status list shared by the admin panel, checkout and REST API.
 *
 * The slug => label map is the single source of truth: slugs are stored in
 * vl_orders.status, labels are what admin timelines and log entries show.
 */
final class OrderStatus
{
    public const string PENDING           = 'pending';
    public const string PROCESSING        = 'processing';
    public const string ON_HOLD           = 'on-hold';
    public const string SHIPPED           = 'shipped';
    public const string COMPLETED         = 'completed';
    public const string REFUND_REQUESTED  = 'refund_requested';
    public const string REFUND_REJECTED   = 'refund_rejected';
    public const string REFUNDED          = 'refunded';
    public const string CANCELLED         = 'cancelled';

    /** @var array<string, string> slug => label */
    public const array STATUSES = [
        self::PENDING          => 'Pending Payment',
        self::PROCESSING       => 'Paid & Processing',
        self::ON_HOLD          => 'On Hold',
        self::SHIPPED          => 'Shipped',
        self::COMPLETED        => 'Completed',
        self::REFUND_REQUESTED => 'Refund Requested',
        self::REFUND_REJECTED  => 'Refund Rejected',
        self::REFUNDED         => 'Refunded',
        self::CANCELLED        => 'Cancelled',
    ];

    /** @var list<string> statuses that count as "paid" */
    public const array PAID = [self::PROCESSING, self::SHIPPED, self::COMPLETED];

    /** @var list<string> statuses that reverse a paid order */
    public const array REVERSED = [self::REFUNDED, self::CANCELLED];

    /** @var list<string> terminal statuses in which an order never reached a paid state */
    public const array UNPAID_TERMINAL = [self::REFUNDED, self::CANCELLED, 'failed'];

    public static function isValid(string $status): bool
    {
        return isset(self::STATUSES[$status]);
    }

    public static function label(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    public static function isPaid(string $status): bool
    {
        return in_array($status, self::PAID, true);
    }

    public static function isReversal(string $status): bool
    {
        return in_array($status, self::REVERSED, true);
    }

    public static function isUnpaidTerminal(string $status): bool
    {
        return in_array($status, self::UNPAID_TERMINAL, true);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::STATUSES);
    }
}
