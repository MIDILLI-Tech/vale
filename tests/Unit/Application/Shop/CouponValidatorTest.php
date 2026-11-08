<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\CouponValidator;
use ValeApp\Domain\Shop\Entity\Coupon;

final class CouponValidatorTest extends TestCase
{
    private InMemoryCouponRepository $repo;
    private CouponValidator $validator;

    protected function setUp(): void
    {
        $this->repo      = new InMemoryCouponRepository();
        $this->validator = new CouponValidator($this->repo);
    }

    private function makeCoupon(string $code = 'SAVE10', string $type = 'percent', float $amount = 10.0): Coupon
    {
        $coupon = new Coupon($code, $type, $amount);
        $this->repo->save($coupon);
        return $coupon;
    }

    public function testEmptyCodeIsRejected(): void
    {
        $result = $this->validator->validateForCart('', 100.0, null, '');
        self::assertFalse($result['valid']);
        self::assertSame('Please enter a coupon code.', $result['error']);
    }

    public function testUnknownCodeIsRejected(): void
    {
        $result = $this->validator->validateForCart('NOPE', 100.0, null, '');
        self::assertFalse($result['valid']);
        self::assertSame('Invalid or expired coupon code.', $result['error']);
    }

    public function testExpiredCouponIsRejected(): void
    {
        $coupon = $this->makeCoupon('OLD');
        $coupon->setExpiresAt(new \DateTimeImmutable('-1 day'));

        $result = $this->validator->validateForCart('OLD', 100.0, null, '');
        self::assertFalse($result['valid']);
    }

    public function testMembershipTargetCouponRejectedForCart(): void
    {
        $coupon = $this->makeCoupon('MEMBER');
        $coupon->setTargetType('membership');

        $result = $this->validator->validateForCart('MEMBER', 100.0, null, '', [1], [1]);
        self::assertFalse($result['valid']);
        self::assertSame('This coupon is only valid for memberships.', $result['error']);
    }

    public function testOnlyUsersCouponRejectedForGuest(): void
    {
        $coupon = $this->makeCoupon('USERS');
        $coupon->setOnlyUsers(true);

        $guest = $this->validator->validateForCart('USERS', 100.0, null, '');
        self::assertFalse($guest['valid']);
        self::assertSame('This coupon requires you to be logged in.', $guest['error']);

        $loggedIn = $this->validator->validateForCart('USERS', 100.0, 5, '');
        self::assertTrue($loggedIn['valid']);
    }

    public function testAllowedEmailsEnforced(): void
    {
        $coupon = $this->makeCoupon('VIP');
        $coupon->setAllowedEmails(['vip@example.com']);

        $missing = $this->validator->validateForCart('VIP', 100.0, null, '');
        self::assertFalse($missing['valid']);
        self::assertStringContainsString('fill in your billing email', (string) $missing['error']);

        $wrong = $this->validator->validateForCart('VIP', 100.0, null, 'other@example.com');
        self::assertFalse($wrong['valid']);

        $ok = $this->validator->validateForCart('VIP', 100.0, null, 'vip@example.com');
        self::assertTrue($ok['valid']);
    }

    public function testMaxUsesPerEmailEnforced(): void
    {
        $coupon = $this->makeCoupon('ONCE');
        $coupon->setMaxUsesPerEmail(1);
        $this->repo->orderEmails['ONCE'] = ['buyer@example.com'];

        $result = $this->validator->validateForCart('ONCE', 100.0, null, 'buyer@example.com');
        self::assertFalse($result['valid']);
        self::assertSame(
            'You have already reached the maximum usage limit for this coupon.',
            $result['error']
        );
    }

    public function testAllowedProductsScope(): void
    {
        $coupon = $this->makeCoupon('SCOPE');
        $coupon->setAllowedProductIds([7]);

        $miss = $this->validator->validateForCart('SCOPE', 100.0, null, '', [3], []);
        self::assertFalse($miss['valid']);
        self::assertSame(
            'This coupon is not valid for the products in your cart.',
            $miss['error']
        );

        $hit = $this->validator->validateForCart('SCOPE', 100.0, null, '', [7, 3], []);
        self::assertTrue($hit['valid']);
    }

    public function testBlockedProductsScope(): void
    {
        $coupon = $this->makeCoupon('NOBUNDLE');
        $coupon->setBlockedProductIds([9]);

        $result = $this->validator->validateForCart('NOBUNDLE', 100.0, null, '', [9, 3], []);
        self::assertFalse($result['valid']);
        self::assertSame(
            'This coupon cannot be used with one or more products in your cart.',
            $result['error']
        );
    }

    public function testAllowedVariationScope(): void
    {
        $coupon = $this->makeCoupon('VARONLY');
        $coupon->setAllowedVariationIds([51]);

        $miss = $this->validator->validateForCart('VARONLY', 100.0, null, '', [5], [], [52]);
        self::assertFalse($miss['valid']);
        self::assertSame(
            'This coupon is not valid for the products in your cart.',
            $miss['error']
        );

        $hit = $this->validator->validateForCart('VARONLY', 100.0, null, '', [5], [], [52, 51]);
        self::assertTrue($hit['valid']);
    }

    public function testBlockedVariationScope(): void
    {
        $coupon = $this->makeCoupon('NOVAR');
        $coupon->setBlockedVariationIds([51]);

        $result = $this->validator->validateForCart('NOVAR', 100.0, null, '', [5], [], [51, 52]);
        self::assertFalse($result['valid']);
        self::assertSame(
            'This coupon cannot be used with one or more products in your cart.',
            $result['error']
        );

        $sibling = $this->validator->validateForCart('NOVAR', 100.0, null, '', [5], [], [52]);
        self::assertTrue($sibling['valid']);
    }

    public function testAllowedParentCoversVariationInCart(): void
    {
        $coupon = $this->makeCoupon('PARCOV');
        $coupon->setAllowedProductIds([5]);

        $hit = $this->validator->validateForCart('PARCOV', 100.0, null, '', [5], [], [51]);
        self::assertTrue($hit['valid']);
    }

    public function testVariationIdsDoNotCollideWithProductIds(): void
    {
        // Same numeric ID in the other namespace must not match:
        // coupon allows product 51, cart has variation 51 on product 5.
        $coupon = $this->makeCoupon('NOCOLLIDE');
        $coupon->setAllowedProductIds([51]);

        $result = $this->validator->validateForCart('NOCOLLIDE', 100.0, null, '', [5], [], [51]);
        self::assertFalse($result['valid']);
    }

    public function testCategoryScopes(): void
    {
        $allowedOnly = $this->makeCoupon('CATS');
        $allowedOnly->setAllowedCategoryIds([4]);
        $miss = $this->validator->validateForCart('CATS', 100.0, null, '', [], [2]);
        self::assertFalse($miss['valid']);
        self::assertSame(
            'This coupon is not valid for the product categories in your cart.',
            $miss['error']
        );

        $blocked = $this->makeCoupon('NOCAT');
        $blocked->setBlockedCategoryIds([6]);
        $hit = $this->validator->validateForCart('NOCAT', 100.0, null, '', [], [6]);
        self::assertFalse($hit['valid']);
        self::assertSame(
            'This coupon cannot be used with one or more product categories in your cart.',
            $hit['error']
        );
    }

    public function testValidCartReturnsCoupon(): void
    {
        $coupon = $this->makeCoupon('GOOD', 'percent', 25.0);

        $result = $this->validator->validateForCart('good', 200.0, null, 'a@b.com', [1, 2], [3, 4]);
        self::assertTrue($result['valid']);
        self::assertNotNull($result['coupon']);
        self::assertSame('GOOD', $result['coupon']->getCode());
        self::assertSame(50.0, $result['coupon']->calculateDiscount(200.0));
    }

    public function testValidateForPlanTargetMismatch(): void
    {
        $this->makeCoupon('SHOPONLY');

        $result = $this->validator->validateForPlan('SHOPONLY', 1);
        self::assertFalse($result['valid']);
        self::assertSame('This coupon is only valid for shop products.', $result['error']);
    }

    public function testValidateForPlanRestrictions(): void
    {
        $coupon = $this->makeCoupon('PLAN5');
        $coupon->setTargetType('membership');
        $coupon->setAllowedMembershipIds([5]);

        self::assertFalse($this->validator->validateForPlan('PLAN5', 1)['valid']);
        self::assertTrue($this->validator->validateForPlan('PLAN5', 5)['valid']);

        $coupon->setBlockedMembershipIds([5]);
        self::assertFalse($this->validator->validateForPlan('PLAN5', 5)['valid']);
    }

    public function testAdminAmountValidation(): void
    {
        $validateAmount = \ValeApp\App\Controllers\Admin\CouponController::validateAmount(...);

        self::assertSame(
            'Percentage discount cannot exceed 100.',
            $validateAmount('percent', 101.0)
        );
        self::assertSame(
            'Coupon amount cannot be negative.',
            $validateAmount('percent', -1.0)
        );
        self::assertSame(
            'Coupon amount cannot be negative.',
            $validateAmount('fixed_cart', -5.0)
        );
        self::assertNull($validateAmount('percent', 100.0));
        self::assertNull($validateAmount('fixed_cart', 250.0));
    }
}
