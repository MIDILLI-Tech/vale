<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;

final class CouponValidator
{
    public function __construct(
        private readonly CouponRepositoryInterface $coupons,
    ) {
    }

    /**
     * Full server-side coupon validation for a shop cart.
     *
     * @param int[] $cartProductIds
     * @param int[] $cartCategoryIds
     * @param int[] $cartVariationIds
     * @return array{valid: bool, coupon: Coupon|null, error: string|null}
     */
    public function validateForCart(
        string $code,
        float $subtotal,
        ?int $userId,
        string $email,
        array $cartProductIds = [],
        array $cartCategoryIds = [],
        array $cartVariationIds = [],
    ): array {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return self::fail('Please enter a coupon code.');
        }

        $coupon = $this->coupons->findByCode($code);
        if ($coupon === null || !$coupon->isValid($subtotal)) {
            return self::fail('Invalid or expired coupon code.');
        }

        if ($coupon->getTargetType() !== 'product') {
            return self::fail('This coupon is only valid for memberships.');
        }

        if ($coupon->isOnlyUsers() && $userId === null) {
            return self::fail('This coupon requires you to be logged in.');
        }

        $email         = strtolower(trim($email));
        $allowedEmails = $coupon->getAllowedEmails();
        if (!empty($allowedEmails)) {
            if ($email === '') {
                return self::fail(
                    'This coupon is restricted to specific email addresses.'
                    . ' Please fill in your billing email first.'
                );
            }
            if (!in_array($email, $allowedEmails, true)) {
                return self::fail('This coupon is not valid for your email address.');
            }
        }

        if ($coupon->getMaxUsesPerEmail() > 0 && $email !== '') {
            $uses = $this->coupons->countUsesByEmail($coupon->getCode(), $email);
            if ($uses >= $coupon->getMaxUsesPerEmail()) {
                return self::fail(
                    'You have already reached the maximum usage limit for this coupon.'
                );
            }
        }

        $productError = $this->validateProductScope($coupon, $cartProductIds, $cartCategoryIds, $cartVariationIds);
        if ($productError !== null) {
            return self::fail($productError);
        }

        return ['valid' => true, 'coupon' => $coupon, 'error' => null];
    }

    /**
     * Full server-side coupon validation for a membership plan.
     *
     * @return array{valid: bool, coupon: Coupon|null, error: string|null}
     */
    public function validateForPlan(string $code, int $planId): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return self::fail('Please enter a coupon code.');
        }

        $coupon = $this->coupons->findByCode($code);
        if ($coupon === null || !$coupon->isValid()) {
            return self::fail('Invalid or expired coupon code.');
        }

        if ($coupon->getTargetType() !== 'membership') {
            return self::fail('This coupon is only valid for shop products.');
        }

        $allowedPlans = $coupon->getAllowedMembershipIds();
        if (!empty($allowedPlans) && !in_array($planId, $allowedPlans, true)) {
            return self::fail('This coupon is not valid for this plan.');
        }

        $blockedPlans = $coupon->getBlockedMembershipIds();
        if (!empty($blockedPlans) && in_array($planId, $blockedPlans, true)) {
            return self::fail('This coupon cannot be used with this plan.');
        }

        return ['valid' => true, 'coupon' => $coupon, 'error' => null];
    }

    /**
     * @param int[] $cartProductIds
     * @param int[] $cartCategoryIds
     * @param int[] $cartVariationIds
     */
    private function validateProductScope(Coupon $coupon, array $cartProductIds, array $cartCategoryIds, array $cartVariationIds = []): ?string
    {
        $cartVariationIds = array_values(array_filter(array_map('intval', $cartVariationIds)));
        if ($cartProductIds === [] && $cartCategoryIds === [] && $cartVariationIds === []) {
            return null;
        }

        $allowedProducts   = $coupon->getAllowedProductIds();
        $allowedVariations = $coupon->getAllowedVariationIds();
        if (!empty($allowedProducts) || !empty($allowedVariations)) {
            $hit = !empty(array_intersect($cartProductIds, $allowedProducts))
                || !empty(array_intersect($cartVariationIds, $allowedVariations));
            if (!$hit) {
                return 'This coupon is not valid for the products in your cart.';
            }
        }

        $blockedProducts   = $coupon->getBlockedProductIds();
        if (!empty($blockedProducts) && !empty(array_intersect($cartProductIds, $blockedProducts))) {
            return 'This coupon cannot be used with one or more products in your cart.';
        }

        $blockedVariations = $coupon->getBlockedVariationIds();
        if (!empty($blockedVariations) && !empty(array_intersect($cartVariationIds, $blockedVariations))) {
            return 'This coupon cannot be used with one or more products in your cart.';
        }

        $allowedCats = $coupon->getAllowedCategoryIds();
        if (!empty($allowedCats) && empty(array_intersect($cartCategoryIds, $allowedCats))) {
            return 'This coupon is not valid for the product categories in your cart.';
        }

        $blockedCats = $coupon->getBlockedCategoryIds();
        if (!empty($blockedCats) && !empty(array_intersect($cartCategoryIds, $blockedCats))) {
            return 'This coupon cannot be used with one or more product categories in your cart.';
        }

        return null;
    }

    /**
     * Build plain coupon lines from resolved cart items.
     *
     * @param array<int, array{product: mixed, qty: int, price: float, variation_id?: int}> $items
     * @return array<int, array{price: float, qty: int, product_id: int, category_ids: int[], variation_id: int}>
     */
    public static function cartLines(array $items): array
    {
        $lines = [];
        foreach ($items as $index => $item) {
            $product = $item['product'] ?? null;
            if (!$product instanceof Product) {
                continue;
            }
            $lines[$index] = [
                'price'        => (float) ($item['price'] ?? 0.0),
                'qty'          => (int) ($item['qty'] ?? 0),
                'product_id'   => (int) $product->getId(),
                'category_ids' => array_map('intval', $product->getCategoryIds()),
                'variation_id' => (int) ($item['variation_id'] ?? 0),
            ];
        }
        return $lines;
    }

    /** @return array{valid: bool, coupon: Coupon|null, error: string|null} */
    private static function fail(?string $message): array
    {
        return ['valid' => false, 'coupon' => null, 'error' => $message];
    }
}
