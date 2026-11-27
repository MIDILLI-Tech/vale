<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

/**
 * Converts a VAT-inclusive order into the net amounts used on a reverse-charge
 * invoice. Prices stay gross everywhere else in Vale, so the embedded VAT is
 * extracted per line and removed from the totals.
 */
final class ReverseChargeCalculator
{
    /**
     * @param array<int, array{price: float, qty: int}> $items
     * @param array{total: float, shipping: float, lines: array<int, array{rate: float, amount: float}>} $taxBreakdown
     * @param array<int, float>|null $discountLines
     *
     * @return array{
     *     items: array<int, array{price: float, subtotal: float}>,
     *     subtotal: float,
     *     shipping: float,
     *     discount: float,
     *     tax: float,
     *     total: float,
     *     vat_exempted: float
     * }
     */
    public function net(
        array $items,
        float $shipping,
        float $discount,
        array $taxBreakdown,
        ?array $discountLines = null,
    ): array {
        $rates = [];
        foreach ($taxBreakdown['lines'] ?? [] as $index => $line) {
            $rates[(int) $index] = max(0.0, (float) ($line['rate'] ?? 0.0));
        }

        $grossSubtotal = 0.0;
        foreach ($items as $item) {
            $grossSubtotal += max(0.0, (float) ($item['price'] ?? 0.0)) * max(0, (int) ($item['qty'] ?? 0));
        }

        $netItems       = [];
        $subtotal       = 0.0;
        $discountTotal  = 0.0;
        $allocatedGross = 0.0;

        foreach (array_values($items) as $index => $item) {
            $qty    = max(0, (int) ($item['qty'] ?? 0));
            $price  = max(0.0, (float) ($item['price'] ?? 0.0));
            $gross  = $price * $qty;
            $rate   = $rates[$index] ?? 0.0;
            $factor = 1 + ($rate / 100);

            $share = 0.0;
            if ($discountLines !== null) {
                $share = min(max(0.0, (float) ($discountLines[$index] ?? 0.0)), $gross);
            } elseif ($discount > 0.0 && $grossSubtotal > 0.0) {
                $share = min($discount - $allocatedGross, $discount * ($gross / $grossSubtotal));
                $share = max(0.0, $share);
            }
            $allocatedGross += $share;

            $netUnit     = round($price / $factor, 2);
            $netSubtotal = round($netUnit * $qty, 2);
            $netDiscount = round($share / $factor, 2);

            $netItems[$index] = ['price' => $netUnit, 'subtotal' => $netSubtotal];
            $subtotal        += $netSubtotal;
            $discountTotal   += $netDiscount;
        }

        $shippingTax   = max(0.0, (float) ($taxBreakdown['shipping'] ?? 0.0));
        $netShipping   = round(max(0.0, $shipping) - $shippingTax, 2);
        $subtotal      = round($subtotal, 2);
        $discountTotal = round(min($discountTotal, $subtotal), 2);
        $total         = round(max(0.0, $subtotal + $netShipping - $discountTotal), 2);
        $grossBase     = round(max(0.0, $grossSubtotal + $shipping - $discount), 2);

        return [
            'items'         => $netItems,
            'subtotal'      => $subtotal,
            'shipping'      => $netShipping,
            'discount'      => $discountTotal,
            'tax'           => 0.0,
            'total'         => $total,
            'vat_exempted'  => round(max(0.0, $grossBase - $total), 2),
        ];
    }
}
