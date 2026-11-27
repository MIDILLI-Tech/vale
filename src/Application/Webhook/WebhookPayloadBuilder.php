<?php

declare(strict_types=1);

namespace ValeApp\Application\Webhook;

use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

/**
 * Builds the `data` payload for outbound product and order webhooks.
 */
final class WebhookPayloadBuilder
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
    ) {
    }

    /** @param array<string, mixed> $extra */
    public function order(Order $order, array $extra = []): array
    {
        $billing = $order->getBilling();

        return array_merge([
            'order_id'             => $order->getId(),
            'order_number'         => $order->getOrderNumber(),
            'order_status'         => $order->getStatus(),
            'order_total'          => (string) $order->getTotal(),
            'currency'             => $order->getCurrency(),
            'order_customer_email' => $billing['email'] ?? '',
            'order_customer_name'  => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
            'age_confirmed_at'     => $order->getAgeConfirmedAt()?->format(\DateTimeInterface::ATOM),
            'items'                => $order->getItems(),
        ], $extra);
    }

    public function product(Product $product): array
    {
        $variations = [];
        try {
            $rows = $this->products->findVariationsByProductId((int) $product->getId());
        } catch (\Throwable) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $attrs = $row['attributes'] ?? [];
            if (is_string($attrs)) {
                $decoded = json_decode($attrs, true);
                $attrs   = is_array($decoded) ? $decoded : [];
            }
            $variations[] = [
                'id'             => (int) ($row['id'] ?? 0),
                'sku'            => $row['sku'] ?? null,
                'attributes'     => $attrs,
                'price'          => (float) ($row['price'] ?? 0),
                'sale_price'     => isset($row['sale_price']) && $row['sale_price'] !== null
                    ? (float) $row['sale_price'] : null,
                'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
                'stock_status'   => $row['stock_status'] ?? 'in_stock',
                'batch_number'   => $row['batch_number'] ?? null,
            ];
        }

        return [
            'product_id'           => $product->getId(),
            'product_name'         => $product->getName(),
            'product_slug'         => (string) $product->getSlug(),
            'product_sku'          => $product->getSku(),
            'product_status'       => $product->getStatus(),
            'product_price'        => $product->getPrice(),
            'product_sale_price'   => $product->getSalePrice(),
            'product_stock'        => $product->getStockQuantity(),
            'product_min_age'      => $product->getMinAge(),
            'product_batch_number' => $product->getBatchNumber(),
            'variations'           => $variations,
        ];
    }
}
