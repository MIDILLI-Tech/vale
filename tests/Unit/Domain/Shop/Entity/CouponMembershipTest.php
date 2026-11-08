<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shop\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shop\Entity\Coupon;

final class CouponMembershipTest extends TestCase
{
    public function testCouponTargetAndMembershipProperties(): void
    {
        $coupon = new Coupon('PLAN50', 'percent', 50.0);

        self::assertSame('PLAN50', $coupon->getCode());
        self::assertSame('percent', $coupon->getType());
        self::assertSame(50.0, $coupon->getAmount());

        // Defaults
        self::assertSame('product', $coupon->getTargetType());
        self::assertSame('once', $coupon->getMembershipDuration());
        self::assertEmpty($coupon->getAllowedMembershipIds());
        self::assertEmpty($coupon->getBlockedMembershipIds());

        // Setters
        $coupon->setTargetType('membership');
        $coupon->setMembershipDuration('forever');
        $coupon->setAllowedMembershipIds([1, 2]);
        $coupon->setBlockedMembershipIds([3]);

        self::assertSame('membership', $coupon->getTargetType());
        self::assertSame('forever', $coupon->getMembershipDuration());
        self::assertSame([1, 2], $coupon->getAllowedMembershipIds());
        self::assertSame([3], $coupon->getBlockedMembershipIds());
    }

    public function testFromRowWithNewFields(): void
    {
        $row = [
            'id'                     => '10',
            'code'                   => 'SAVE10',
            'type'                   => 'fixed_cart',
            'amount'                 => '10.00',
            'target_type'            => 'membership',
            'allowed_membership_ids' => '5,6',
            'blocked_membership_ids' => '7',
            'membership_duration'    => 'forever',
            'enabled'                => '1',
            'uses_count'             => '2',
        ];

        $coupon = Coupon::fromRow($row);

        self::assertSame(10, $coupon->getId());
        self::assertSame('SAVE10', $coupon->getCode());
        self::assertSame('fixed_cart', $coupon->getType());
        self::assertSame(10.0, $coupon->getAmount());
        self::assertSame('membership', $coupon->getTargetType());
        self::assertSame([5, 6], $coupon->getAllowedMembershipIds());
        self::assertSame([7], $coupon->getBlockedMembershipIds());
        self::assertSame('forever', $coupon->getMembershipDuration());
    }
}
