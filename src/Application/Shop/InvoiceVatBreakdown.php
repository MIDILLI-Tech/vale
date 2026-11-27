<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Domain\Shop\Entity\Order;

/**
 * Splits an order's gross totals into net and VAT per rate, including the VAT
 * share embedded in the shipping fee, so an invoice can list every rate
 * separately (§14 UStG).
 */
final class InvoiceVatBreakdown
{
    /**
     * @return list<array{rate: float, net: float, tax: float}>
     */
    public function build(Order $order, float $fallbackRate): array
    {
        $rows = [];

        foreach ($order->getItems() as $item) {
            $quantity = max(1, (int) ($item['qty'] ?? $item['quantity'] ?? 1));
            $gross    = round((float) ($item['price'] ?? 0) * $quantity, 2);
            $rate     = isset($item['tax_rate'])   && $item['tax_rate']   !== null ? (float) $item['tax_rate'] : $fallbackRate;
            $tax      = isset($item['tax_amount']) && $item['tax_amount'] !== null
                ? round((float) $item['tax_amount'], 2)
                : ($rate > 0 ? round($gross - $gross / (1 + $rate / 100), 2) : 0.0);

            $key = number_format($rate, 2, '.', '');
            $rows[$key] ??= ['rate' => $rate, 'net' => 0.0, 'tax' => 0.0];
            $rows[$key]['net'] += round($gross - $tax, 2);
            $rows[$key]['tax'] += $tax;
        }

        $shippingTax = round((float) $order->getTax() - array_sum(array_column($rows, 'tax')), 2);
        if ($shippingTax > 0.005) {
            $key = number_format($fallbackRate, 2, '.', '');
            $rows[$key] ??= ['rate' => $fallbackRate, 'net' => 0.0, 'tax' => 0.0];
            $rows[$key]['net'] += round((float) $order->getShipping() - $shippingTax, 2);
            $rows[$key]['tax'] += $shippingTax;
        }

        return array_values(array_map(
            static fn (array $row): array => [
                'rate' => (float) $row['rate'],
                'net'  => round((float) $row['net'], 2),
                'tax'  => round((float) $row['tax'], 2),
            ],
            $rows,
        ));
    }
}
