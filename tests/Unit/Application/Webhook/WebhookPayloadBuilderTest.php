<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Webhook;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Webhook\WebhookPayloadBuilder;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

final class WebhookPayloadBuilderTest extends TestCase
{
    public function testProductPayloadCarriesComplianceAndVariationBatch(): void
    {
        $product = new Product('Compliant Device', new Slug('compliant-device'));
        $product->setId(7);
        $product->setSku('DEV-1');
        $product->setMinAge(18);
        $product->setBatchNumber('LOT-PARENT');

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findVariationsByProductId')->willReturn([
            [
                'id'             => 11,
                'sku'            => 'DEV-1-RED',
                'attributes'     => json_encode(['Color' => 'Red']),
                'price'          => '19.90',
                'sale_price'     => null,
                'stock_quantity' => 4,
                'stock_status'   => 'in_stock',
                'batch_number'   => 'LOT-VAR',
            ],
        ]);

        $payload = (new WebhookPayloadBuilder($products))->product($product);

        self::assertSame(7, $payload['product_id']);
        self::assertSame(18, $payload['product_min_age']);
        self::assertSame('LOT-PARENT', $payload['product_batch_number']);
        self::assertSame('LOT-VAR', $payload['variations'][0]['batch_number']);
        self::assertSame(['Color' => 'Red'], $payload['variations'][0]['attributes']);
    }

    public function testOrderPayloadCarriesAgeConfirmationAndItemBatch(): void
    {
        $order = Order::fromRow([
            'id'             => 42,
            'order_number'   => 'VL-1001',
            'status'         => 'processing',
            'locale'         => 'en',
            'currency'       => 'EUR',
            'subtotal'       => '49.00',
            'tax'            => '0.00',
            'shipping'       => '0.00',
            'discount'       => '0.00',
            'total'          => '49.00',
            'billing'        => json_encode(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']),
            'created_at'     => '2026-11-27 09:59:00',
        ]);
        $order->setAgeConfirmedAt(new \DateTimeImmutable('2026-11-27T10:00:00+00:00'));
        $order->setItems([
            [
                'product_id'   => 7,
                'name'         => 'Compliant Device',
                'sku'          => 'DEV-1',
                'batch_number' => 'LOT-VAR',
                'price'        => 49.00,
                'qty'          => 1,
                'subtotal'     => 49.00,
            ],
        ]);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $payload  = (new WebhookPayloadBuilder($products))->order($order, ['new_status' => 'shipped']);

        self::assertSame('2026-11-27T10:00:00+00:00', $payload['age_confirmed_at']);
        self::assertSame('LOT-VAR', $payload['items'][0]['batch_number']);
        self::assertSame('Ada Lovelace', $payload['order_customer_name']);
        self::assertSame('shipped', $payload['new_status']);
    }
}
