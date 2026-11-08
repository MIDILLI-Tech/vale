<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

final class IdealoFeedService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly MediaRepositoryInterface $media,
        private readonly string $siteUrl,
        private readonly string $currency = 'EUR',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?SettingsRepositoryInterface $settings = null,
    ) {
    }

    /**
     * Builds a CSV feed following the official idealo CSV-importer spec:
     * UTF-8, comma-separated (RFC-4180), '.' decimals, ';' lists.
     *
     * @see https://idealo.github.io/csv-importer/en/csv
     */
    public function generateCsv(): string
    {
        $output = fopen('php://memory', 'r+');
        if ($output === false) {
            return '';
        }

        // Column names are the attribute names from the idealo spec.
        // Column order is arbitrary per spec; rows must follow this order.
        $header = [
            'sku',
            'brand',
            'title',
            'categoryPath',
            'url',
            'eans',
            'description',
            'price',
            'delivery',
            'deliveryCosts_dhl',
        ];
        if ($this->paymentCostsColumn() !== null) {
            $header[] = $this->paymentCostsColumn();
        }
        $header[] = 'imageUrls';
        $header[] = 'download';
        fputcsv($output, $header, ',', '"', '\\');

        $page    = 1;
        $perPage = 200;

        do {
            $products = $this->products->findAll(
                new Pagination($page, $perPage),
                ['status' => 'published'],
            );

            foreach ($products as $product) {
                if ($product->isGmcExclude()) {
                    continue;
                }

                if ($product->getType() === 'variable') {
                    $variants = $this->buildVariants($product);
                    if ($variants !== []) {
                        foreach ($variants as $variant) {
                            $this->writeVariant($output, $product, $variant);
                        }
                    } else {
                        $this->writeProduct($output, $product);
                    }
                } else {
                    $this->writeProduct($output, $product);
                }
            }

            $page++;
        } while (count($products) === $perPage);

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    private function writeProduct($output, Product $product): void
    {
        if ($product->getSku() === null || $product->getSku() === '') {
            return;
        }

        $cells = [
            $product->getSku(),
            $product->getBrand() ?? '',
            $this->sanitize($product->getName()),
            '',
            $this->productUrl($product),
            $product->getGtin() ?? '',
            mb_substr($this->sanitize(strip_tags($product->getDescription() ?? '')), 0, 1000),
            number_format($product->getEffectivePrice(), 2, '.', ''),
            $this->deliveryText($product),
            $this->deliveryCost($product),
        ];
        if ($this->paymentCostsColumn() !== null) {
            $cells[] = '0.00';
        }
        $cells[] = $this->productImageUrl($product);
        $cells[] = $product->isDigital() ? 'true' : '';

        fputcsv($output, $cells, ',', '"', '\\');
    }

    private function writeVariant($output, Product $product, array $variant): void
    {
        $varSku = !empty($variant['sku']) ? $variant['sku'] : $product->getSku() . '-' . $variant['id'];
        if (empty($varSku)) {
            return;
        }

        $varPrice       = !empty($variant['price']) ? (float) $variant['price'] : $product->getPrice();
        $varSalePrice   = !empty($variant['sale_price']) ? (float) $variant['sale_price'] : null;
        $effectivePrice = $varSalePrice ?? $varPrice;

        $varImageUrl = '';
        $varImageId  = !empty($variant['image_id']) ? (int) $variant['image_id'] : null;
        if ($varImageId !== null) {
            try {
                $media = $this->media->findById($varImageId);
                if ($media !== null) {
                    $varImageUrl = $this->mediaUrl($media->getUrl());
                }
            } catch (\Throwable) {
            }
        }

        $varTitle  = $product->getName();
        $attrs     = [];
        if (is_string($variant['attributes'] ?? null)) {
            $attrs = json_decode($variant['attributes'], true) ?? [];
        } elseif (is_array($variant['attributes'] ?? null)) {
            $attrs = $variant['attributes'];
        }
        $attrParts = [];
        foreach ($attrs as $av) {
            if (is_string($av) && $av !== '') {
                $attrParts[] = $av;
            }
        }
        if ($attrParts !== []) {
            $varTitle .= ' - ' . implode(' / ', $attrParts);
        }

        $cells = [
            $varSku,
            $product->getBrand() ?? '',
            $this->sanitize($varTitle),
            '',
            $this->productUrl($product),
            $product->getGtin() ?? '',
            mb_substr($this->sanitize(strip_tags($product->getDescription() ?? '')), 0, 1000),
            number_format($effectivePrice, 2, '.', ''),
            $this->deliveryText($product, $variant),
            $this->deliveryCost($product),
        ];
        if ($this->paymentCostsColumn() !== null) {
            $cells[] = '0.00';
        }
        $cells[] = $varImageUrl ?: $this->productImageUrl($product);
        $cells[] = $product->isDigital() ? 'true' : '';

        fputcsv($output, $cells, ',', '"', '\\');
    }

    /**
     * idealo requires delivery time info ("delivery" column). Resolves from
     * variation > product > global shipping-time settings, with a stock-based
     * heuristic fallback so the column is never empty. Merchants should
     * configure real shipping times per product or globally.
     */
    private function deliveryText(Product $product, ?array $variationRow = null): string
    {
        if ($product->isDigital()) {
            return 'Download';
        }

        $min = $this->toDays($variationRow['shipping_time_min'] ?? null)
            ?? $product->getShippingTimeMin()
            ?? $this->intSetting('shipping_time_min');
        $max = $this->toDays($variationRow['shipping_time_max'] ?? null)
            ?? $product->getShippingTimeMax()
            ?? $this->intSetting('shipping_time_max');

        if ($min !== null && $max !== null) {
            return $min === $max
                ? "Delivery in {$min} working days"
                : "Delivered in {$min}-{$max} working days";
        }

        return $product->isInStock() ? '1-3 working days' : '7-14 working days';
    }

    /**
     * idealo requires at least one deliveryCosts_<provider> column with the
     * price incl. VAT ('0.00' when free). Uses the product shipping cost;
     * empty when unknown so the merchant can spot and fix it.
     */
    private function deliveryCost(Product $product): string
    {
        if ($product->isDigital() || $product->getShippingClass() === 'free') {
            return '0.00';
        }

        $cost = $product->getShippingCost();
        if ($cost === null) {
            return '';
        }

        return $cost > 0 ? number_format($cost, 2, '.', '') : '0.00';
    }

    /**
     * idealo requires at least one paymentCosts_<method> column. Maps the
     * shop's active payment gateway to the closest idealo payment method.
     * Returns null when the gateway cannot be mapped (column omitted).
     */
    private function paymentCostsColumn(): ?string
    {
        $gateway = strtolower((string) ($this->settings?->get('payment_active_gateway', 'stripe') ?? ''));

        return match ($gateway) {
            'paypal'                                               => 'paymentCosts_paypal',
            'stripe', 'checkoutcom', 'twocheckout', 'mollie'       => 'paymentCosts_credit_card',
            default                                                => null,
        };
    }

    private function toDays(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $days = (int) $value;
        return $days >= 0 ? $days : null;
    }

    private function intSetting(string $key): ?int
    {
        $value = $this->settings?->get($key, '') ?? '';
        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    private function productUrl(Product $product): string
    {
        $shopPrefix = '/' . ltrim($this->settings?->get('routing_shop_prefix', '/shop') ?? '/shop', '/');
        return rtrim($this->siteUrl, '/') . $shopPrefix . '/' . ltrim((string) $product->getSlug(), '/');
    }

    private function productImageUrl(Product $product): string
    {
        if ($product->getFeaturedImageId() === null) {
            return '';
        }
        try {
            $media = $this->media->findById($product->getFeaturedImageId());
            if ($media !== null) {
                return $this->mediaUrl($media->getUrl());
            }
        } catch (\Throwable) {
        }
        return '';
    }

    private function mediaUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return rtrim($this->siteUrl, '/') . '/' . ltrim($path, '/');
    }

    private function sanitize(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        return trim($text);
    }

    /** @return list<array<string, mixed>> */
    private function buildVariants(Product $product): array
    {
        try {
            return $this->products->findVariationsByProductId($product->getId() ?? 0);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch variations for product #{id}: {msg}', [
                'id'  => $product->getId(),
                'msg' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
