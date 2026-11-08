<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

final class VariationPrice
{
    /**
     * Resolves the effective price of a raw vl_product_variations row,
     * honouring the sale-price window (same rules as checkout).
     *
     * @param array<string, mixed> $variationRow
     */
    public static function resolve(array $variationRow): float
    {
        $price = (float) ($variationRow['price'] ?? 0.0);

        if (empty($variationRow['sale_price'])) {
            return $price;
        }

        $hasWindow = !empty($variationRow['sale_price_start']) || !empty($variationRow['sale_price_end']);
        if (!$hasWindow) {
            return (float) $variationRow['sale_price'];
        }

        $now   = new \DateTimeImmutable();
        $start = !empty($variationRow['sale_price_start']) ? new \DateTimeImmutable((string) $variationRow['sale_price_start']) : null;
        $end   = !empty($variationRow['sale_price_end']) ? new \DateTimeImmutable((string) $variationRow['sale_price_end']) : null;

        if ($start !== null && $now < $start) {
            return $price;
        }
        if ($end !== null && $now >= $end) {
            return $price;
        }

        return (float) $variationRow['sale_price'];
    }
}
