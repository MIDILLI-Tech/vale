<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Product;

/**
 * Resolves the estimated delivery range (min/max days) for products,
 * variations and orders using the usual override chain:
 * variation country override > variation > product country override > product > country rate > global default.
 */
final class DeliveryEstimateService
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * @return array{min: ?int, max: ?int}
     */
    public function estimateForProduct(?Product $product = null, ?array $variationRow = null, ?string $country = null): array
    {
        $min = $this->intSetting('shipping_time_min');
        $max = $this->intSetting('shipping_time_max');

        $rate = $this->resolveRateForCountry($country);
        if (isset($rate['shipping_time_min']) && $rate['shipping_time_min'] !== null && $rate['shipping_time_min'] !== '') {
            $min = (int) $rate['shipping_time_min'];
        }
        if (isset($rate['shipping_time_max']) && $rate['shipping_time_max'] !== null && $rate['shipping_time_max'] !== '') {
            $max = (int) $rate['shipping_time_max'];
        }

        if ($product !== null) {
            if ($product->getShippingTimeMin() !== null) {
                $min = $product->getShippingTimeMin();
            }
            if ($product->getShippingTimeMax() !== null) {
                $max = $product->getShippingTimeMax();
            }

            $override = $this->overrideForCountry($product->getShippingCountryOverrides(), $country);
            if ($this->hasTime($override['shipping_time_min'] ?? null)) {
                $min = (int) $override['shipping_time_min'];
            }
            if ($this->hasTime($override['shipping_time_max'] ?? null)) {
                $max = (int) $override['shipping_time_max'];
            }
        }

        if ($variationRow !== null) {
            if (!empty($variationRow['shipping_time_min'])) {
                $min = (int) $variationRow['shipping_time_min'];
            }
            if (!empty($variationRow['shipping_time_max'])) {
                $max = (int) $variationRow['shipping_time_max'];
            }

            $varOverrides = $this->decodeOverrides($variationRow['shipping_country_overrides'] ?? null);
            $varOverride  = $this->overrideForCountry($varOverrides, $country);
            if ($this->hasTime($varOverride['shipping_time_min'] ?? null)) {
                $min = (int) $varOverride['shipping_time_min'];
            }
            if ($this->hasTime($varOverride['shipping_time_max'] ?? null)) {
                $max = (int) $varOverride['shipping_time_max'];
            }
        }

        return $this->normalize($min, $max);
    }

    /**
     * Combine per-item estimates into an order-level range (earliest min, latest max).
     *
     * @param array<array{product?: Product, variation_row?: ?array}> $items
     * @return array{min: ?int, max: ?int}
     */
    public function estimateForOrder(array $items, ?string $country = null): array
    {
        $min = null;
        $max = null;

        foreach ($items as $item) {
            $product = $item['product'] ?? null;
            if (!$product instanceof Product || $product->isDigital() || $product->getShippingClass() === 'do_not_ship') {
                continue;
            }
            $estimate = $this->estimateForProduct($product, $item['variation_row'] ?? null, $country);
            if ($estimate['min'] !== null) {
                $min = $min === null ? $estimate['min'] : min($min, $estimate['min']);
            }
            if ($estimate['max'] !== null) {
                $max = $max === null ? $estimate['max'] : max($max, $estimate['max']);
            }
        }

        return $this->normalize($min, $max);
    }

    private function intSetting(string $key): ?int
    {
        $value = $this->settings->get($key, '') ?? '';
        return $value !== '' ? (int) $value : null;
    }

    /**
     * @return array{rate?: string, shipping_time_min?: int|string|null, shipping_time_max?: int|string|null}
     */
    private function resolveRateForCountry(?string $country): array
    {
        if ($country === null || $country === '') {
            return [];
        }
        $country = strtoupper(trim($country));
        $json    = $this->settings->get('shipping_country_rates', '{}') ?? '{}';
        $rates   = json_decode((string) $json, true);
        if (!is_array($rates)) {
            return [];
        }
        $row = $rates[$country] ?? null;
        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string, mixed> $overrides Per-country override map.
     * @return array<string, mixed>
     */
    private function overrideForCountry(array $overrides, ?string $country): array
    {
        if ($country === null || $country === '') {
            return [];
        }
        $row = $overrides[strtoupper(trim($country))] ?? null;
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeOverrides(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (is_string($json) && $json !== '' && json_validate($json)) {
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function hasTime(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * @return array{min: ?int, max: ?int}
     */
    private function normalize(?int $min, ?int $max): array
    {
        if ($min === null && $max === null) {
            return ['min' => null, 'max' => null];
        }
        if ($min === null) {
            $min = $max;
        }
        if ($max === null) {
            $max = $min;
        }
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        return ['min' => $min, 'max' => $max];
    }
}
