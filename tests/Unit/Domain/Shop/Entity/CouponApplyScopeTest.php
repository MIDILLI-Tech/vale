<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shop\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\CouponValidator;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Domain\Shop\Entity\Product;

final class CouponApplyScopeTest extends TestCase
{
    /** @return array<int, array{price: float, qty: int, product_id: int, category_ids: int[]}> */
    private function lines(): array
    {
        return [
            ['price' => 100.0, 'qty' => 1, 'product_id' => 1, 'category_ids' => [10]],
            ['price' => 40.0, 'qty' => 2, 'product_id' => 2, 'category_ids' => [11]],
            ['price' => 250.0, 'qty' => 1, 'product_id' => 3, 'category_ids' => [10]],
        ];
    }

    public function testDefaultScopeIsAllEligible(): void
    {
        $coupon = new Coupon('SCOPE', 'percent', 10.0);
        self::assertSame(Coupon::APPLY_SCOPE_ALL, $coupon->getApplyScope());

        $coupon->setApplyScope('bogus');
        self::assertSame(Coupon::APPLY_SCOPE_ALL, $coupon->getApplyScope());
    }

    public function testAllEligibleUsesEligibleLinesTotal(): void
    {
        $coupon = new Coupon('ALL10', 'percent', 10.0);
        $coupon->setAllowedProductIds([1, 3]);

        // Eligible: 100 + 250 = 350 → 10% = 35 (product 2 excluded).
        self::assertSame(35.0, $coupon->calculateDiscountForLines($this->lines()));

        $allocation = $coupon->allocateDiscount($this->lines());
        self::assertSame(10.0, $allocation[0]);
        self::assertSame(25.0, $allocation[2]);
        self::assertArrayNotHasKey(1, $allocation);
        self::assertSame(35.0, round(array_sum($allocation), 2));
    }

    public function testCheapestScopeDiscountsSingleUnit(): void
    {
        $coupon = new Coupon('CHEAP20', 'percent', 20.0);
        $coupon->setApplyScope(Coupon::APPLY_SCOPE_CHEAPEST);

        // Cheapest unit is 40 (qty 2 line) → 20% of one unit = 8.
        self::assertSame(8.0, $coupon->calculateDiscountForLines($this->lines()));
        self::assertSame([1 => 8.0], $coupon->allocateDiscount($this->lines()));
    }

    public function testMostExpensiveScopeDiscountsSingleUnit(): void
    {
        $coupon = new Coupon('PRICY20', 'percent', 20.0);
        $coupon->setApplyScope(Coupon::APPLY_SCOPE_EXPENSIVE);

        // Most expensive unit is 250 → 20% = 50.
        self::assertSame(50.0, $coupon->calculateDiscountForLines($this->lines()));
        self::assertSame([2 => 50.0], $coupon->allocateDiscount($this->lines()));
    }

    public function testFixedAmountCappedAtSingleUnit(): void
    {
        $coupon = new Coupon('FIX100', 'fixed_cart', 100.0);
        $coupon->setApplyScope(Coupon::APPLY_SCOPE_CHEAPEST);

        // Fixed 100 capped at cheapest unit price 40.
        self::assertSame(40.0, $coupon->calculateDiscountForLines($this->lines()));
    }

    public function testFixedAmountCappedAtEligibleTotal(): void
    {
        $coupon = new Coupon('FIX500', 'fixed_cart', 500.0);
        $coupon->setAllowedProductIds([1, 3]);

        // Eligible total 350 → capped at 350.
        self::assertSame(350.0, $coupon->calculateDiscountForLines($this->lines()));
    }

    public function testBlockedLinesExcluded(): void
    {
        $coupon = new Coupon('NOBIG', 'percent', 10.0);
        $coupon->setBlockedProductIds([3]);

        // Eligible: 100 + 80 = 180 → 18.
        self::assertSame(18.0, $coupon->calculateDiscountForLines($this->lines()));
    }

    public function testNoEligibleLinesGivesZero(): void
    {
        $coupon = new Coupon('NONE', 'percent', 10.0);
        $coupon->setAllowedProductIds([999]);

        self::assertSame(0.0, $coupon->calculateDiscountForLines($this->lines()));
        self::assertSame([], $coupon->allocateDiscount($this->lines()));
    }

    public function testFromRowReadsApplyScope(): void
    {
        $row = [
            'id'          => 1, 'code' => 'X', 'type' => 'percent', 'amount' => 10,
            'apply_scope' => 'cheapest',
        ];
        self::assertSame('cheapest', Coupon::fromRow($row)->getApplyScope());

        $row['apply_scope'] = 'bogus';
        self::assertSame('all_eligible', Coupon::fromRow($row)->getApplyScope());

        unset($row['apply_scope']);
        self::assertSame('all_eligible', Coupon::fromRow($row)->getApplyScope());
    }

    public function testCartLinesBuildsPlainLines(): void
    {
        $p1 = new Product('A', new Slug('a'));
        $p1->setId(7);
        $p1->setCategoryIds([4, 5]);

        $lines = CouponValidator::cartLines([
            ['product' => $p1, 'qty' => 2, 'price' => 30.0],
            ['product' => 'not-a-product', 'qty' => 1, 'price' => 10.0],
        ]);

        self::assertSame(
            [0 => ['price' => 30.0, 'qty' => 2, 'product_id' => 7, 'category_ids' => [4, 5], 'variation_id' => 0]],
            $lines
        );
    }

    public function testCartLinesPreservesVariationId(): void
    {
        $p1 = new Product('A', new Slug('a'));
        $p1->setId(7);
        $p1->setCategoryIds([4]);

        $lines = CouponValidator::cartLines([
            ['product' => $p1, 'qty' => 1, 'price' => 30.0, 'variation_id' => 55],
        ]);

        self::assertSame(55, $lines[0]['variation_id']);
    }

    public function testAllowedParentCoversAllVariations(): void
    {
        $coupon = new Coupon('PVAR', 'percent', 10.0);
        $coupon->setAllowedProductIds([5]);

        self::assertTrue($coupon->isEligibleLine(5, [], 51));
        self::assertTrue($coupon->isEligibleLine(5, [], 0));
        self::assertFalse($coupon->isEligibleLine(6, [], 52));
    }

    public function testAllowedSingleVariation(): void
    {
        $coupon = new Coupon('ONEVAR', 'percent', 10.0);
        $coupon->setAllowedVariationIds([51]);

        self::assertTrue($coupon->isEligibleLine(5, [], 51));
        self::assertFalse($coupon->isEligibleLine(5, [], 52));
        self::assertFalse($coupon->isEligibleLine(5, [], 0));
    }

    public function testBlockedParentBlocksAllVariations(): void
    {
        $coupon = new Coupon('NOPAR', 'percent', 10.0);
        $coupon->setBlockedProductIds([5]);

        self::assertFalse($coupon->isEligibleLine(5, [], 51));
        self::assertFalse($coupon->isEligibleLine(5, [], 0));
        self::assertTrue($coupon->isEligibleLine(6, [], 52));
    }

    public function testBlockedSingleVariationLeavesSiblingsEligible(): void
    {
        $coupon = new Coupon('NOVAR', 'percent', 10.0);
        $coupon->setBlockedVariationIds([51]);

        self::assertFalse($coupon->isEligibleLine(5, [], 51));
        self::assertTrue($coupon->isEligibleLine(5, [], 52));
        self::assertTrue($coupon->isEligibleLine(5, [], 0));
    }

    public function testVariationDiscountOnlyAppliesToEligibleLines(): void
    {
        $coupon = new Coupon('VDISC', 'percent', 10.0);
        $coupon->setAllowedVariationIds([51]);

        $lines = [
            ['price' => 100.0, 'qty' => 1, 'product_id' => 5, 'category_ids' => [], 'variation_id' => 51],
            ['price' => 100.0, 'qty' => 1, 'product_id' => 5, 'category_ids' => [], 'variation_id' => 52],
        ];

        // Only the allowed variation's line counts: 10% of 100 = 10.
        self::assertSame(10.0, $coupon->calculateDiscountForLines($lines));
        self::assertSame([0 => 10.0], $coupon->allocateDiscount($lines));
    }

    public function testFromRowReadsVariationIds(): void
    {
        $row = [
            'id'                    => 1, 'code' => 'X', 'type' => 'percent', 'amount' => 10,
            'allowed_variation_ids' => '51,52',
            'blocked_variation_ids' => '53',
        ];
        $coupon = Coupon::fromRow($row);
        self::assertSame([51, 52], $coupon->getAllowedVariationIds());
        self::assertSame([53], $coupon->getBlockedVariationIds());
    }
}
