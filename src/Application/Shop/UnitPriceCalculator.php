<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

/**
 * Derives the German "Grundpreis" (price per reference quantity) from a
 * product's content amount. Imperial units are converted to their metric
 * reference so the figure stays comparable.
 */
final class UnitPriceCalculator
{
    /** @var array<string, array{factor: float, label: string}> */
    private const REFERENCES = [
        'g'     => ['factor' => 0.001,        'label' => '1 kg'],
        'kg'    => ['factor' => 1.0,          'label' => '1 kg'],
        'oz'    => ['factor' => 0.0283495231, 'label' => '1 kg'],
        'lb'    => ['factor' => 0.45359237,   'label' => '1 kg'],
        'ml'    => ['factor' => 0.001,        'label' => '1 l'],
        'l'     => ['factor' => 1.0,          'label' => '1 l'],
        'fl_oz' => ['factor' => 0.0295735296, 'label' => '1 l'],
        'gal'   => ['factor' => 3.785411784,  'label' => '1 l'],
        'm'     => ['factor' => 1.0,          'label' => '1 m'],
        'ft'    => ['factor' => 0.3048,       'label' => '1 m'],
        'm2'    => ['factor' => 1.0,          'label' => '1 m²'],
        'ft2'   => ['factor' => 0.09290304,   'label' => '1 m²'],
    ];

    /** @return array{price: float, label: string}|null */
    public function resolve(?string $unit, ?float $amount, float $price): ?array
    {
        if ($unit === null || $unit === '' || $unit === 'piece' || $amount === null || $amount <= 0.0 || $price <= 0.0) {
            return null;
        }

        $reference = self::REFERENCES[$unit] ?? null;
        if ($reference === null) {
            return null;
        }

        $referenceAmount = $amount * $reference['factor'];
        if ($referenceAmount <= 0.0) {
            return null;
        }

        return [
            'price' => round($price / $referenceAmount, 2),
            'label' => $reference['label'],
        ];
    }
}
