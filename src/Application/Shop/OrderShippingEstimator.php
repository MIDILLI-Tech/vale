<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

/**
 * Resolves the delivery estimate for a stored order by mapping its items back
 * to products/variations. Shared by the admin panel (order detail, emails)
 * and the tracking notification path.
 */
final class OrderShippingEstimator
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly DeliveryEstimateService $estimates,
    ) {
    }

    /**
     * @return array{min: ?int, max: ?int}
     */
    public function estimate(Order $order): array
    {
        $billing = $order->getBilling();
        $country = strtoupper(trim((string) ($billing['country'] ?? '')));

        $productIds   = [];
        $variationIds = [];
        foreach ($order->getItems() as $item) {
            $productIds[]   = (int) ($item['product_id'] ?? 0);
            $variationIds[] = (int) ($item['variation_id'] ?? 0);
        }

        $products = [];
        foreach (array_unique(array_filter($productIds)) as $id) {
            $product = $this->products->findById($id);
            if ($product !== null) {
                $products[$id] = $product;
            }
        }

        $variationMap = [];
        foreach ($this->products->findVariationsByProductIds(array_unique(array_filter($variationIds))) as $rows) {
            foreach ($rows as $row) {
                $variationMap[(int) ($row['id'] ?? 0)] = $row;
            }
        }

        $rows = [];
        foreach ($order->getItems() as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if (!isset($products[$productId])) {
                continue;
            }
            $variationId = (int) ($item['variation_id'] ?? 0);
            $rows[]      = [
                'product'       => $products[$productId],
                'variation_row' => $variationId > 0 ? ($variationMap[$variationId] ?? null) : null,
            ];
        }

        return $this->estimates->estimateForOrder($rows, $country !== '' ? $country : null);
    }
}
