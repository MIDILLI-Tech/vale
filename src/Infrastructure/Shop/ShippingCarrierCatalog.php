<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Shop;

use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

/**
 * Shipping carrier catalog: reference-data carriers merged with the
 * custom_shipping_carriers setting, plus tracking-URL resolution.
 */
final class ShippingCarrierCatalog
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * @return list<array{value: string, label: string, url_template: string|null}>
     */
    public function all(): array
    {
        $refData    = require config_path('reference-data.php');
        $builtIn    = is_array($refData['shipping_carriers'] ?? null) ? $refData['shipping_carriers'] : [];
        $custom     = json_decode((string) $this->settings->get('custom_shipping_carriers', '[]'), true) ?: [];
        $normalized = [];
        $seen       = [];

        foreach ($builtIn as $c) {
            if (!is_array($c)) {
                continue;
            }
            $val = strtolower(trim((string) ($c['value'] ?? '')));
            if ($val === '' || isset($seen[$val])) {
                continue;
            }
            $seen[$val]   = true;
            $normalized[] = $c;
        }

        foreach ($custom as $c) {
            if (!is_array($c)) {
                continue;
            }
            if (empty($c['value']) && !empty($c['name'])) {
                $c['value'] = strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '-', (string) $c['name']), '-'));
            }
            if (empty($c['label']) && !empty($c['name'])) {
                $c['label'] = $c['name'];
            }
            if (empty($c['url_template']) && !empty($c['url'])) {
                $c['url_template'] = $c['url'];
            }
            $val = strtolower(trim((string) ($c['value'] ?? '')));
            if ($val === '' || $val === 'other' || isset($seen[$val])) {
                continue;
            }
            $seen[$val]   = true;
            $normalized[] = $c;
        }

        usort($normalized, static function ($a, $b): int {
            $labelA = $a['label'] ?? $a['value'] ?? '';
            $labelB = $b['label'] ?? $b['value'] ?? '';
            return strcasecmp((string) $labelA, (string) $labelB);
        });

        return $normalized;
    }

    /**
     * Resolve a public tracking URL for a carrier + tracking number.
     * The "other" carrier treats the number itself as the URL.
     */
    public function trackingUrl(?string $carrier, string $trackingNumber): string
    {
        if ($carrier === null || $carrier === '') {
            return '';
        }

        foreach ($this->all() as $c) {
            if (($c['value'] ?? null) === $carrier && !empty($c['url_template'])) {
                return str_replace('{tracking_number}', urlencode($trackingNumber), (string) $c['url_template']);
            }
        }

        return '';
    }
}
