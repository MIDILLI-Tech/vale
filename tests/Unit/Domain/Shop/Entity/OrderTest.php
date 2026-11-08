<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shop\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shop\Entity\Order;

final class OrderTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $order = new Order('ORD-001', ['name' => 'John'], 5);

        self::assertSame('ORD-001', $order->getOrderNumber());
        self::assertSame(['name' => 'John'], $order->getBilling());
        self::assertSame(5, $order->getUserId());
        self::assertSame('pending', $order->getStatus());
        self::assertSame('en', $order->getLocale());
        self::assertSame('USD', $order->getCurrency());
        self::assertSame(0.0, $order->getSubtotal());
        self::assertSame(0.0, $order->getTax());
        self::assertSame(0.0, $order->getShipping());
        self::assertSame(0.0, $order->getDiscount());
        self::assertSame(0.0, $order->getTotal());
        self::assertNull($order->getCouponCode());
        self::assertSame([], $order->getShippingAddress());
        self::assertNull($order->getCustomerNote());
        self::assertNull($order->getPaymentMethod());
        self::assertNull($order->getTransactionId());
        self::assertNull($order->getPaymentToken());
        self::assertNull($order->getPaidAt());
        self::assertNull($order->getCarrier());
        self::assertNull($order->getTrackingNumber());
        self::assertSame('shop', $order->getOrderType());
        self::assertNull($order->getPlanId());
        self::assertNull($order->getSubscriptionId());
        self::assertNull($order->getPlanName());
        self::assertNull($order->isInventoryProcessed());
        self::assertNull($order->isInventoryRestored());
        self::assertSame([], $order->getItems());
    }

    public function testSetters(): void
    {
        $order = new Order('ORD-001', []);

        $order->setStatus('processing');
        $order->setLocale('tr');
        $order->setCurrency('EUR');
        $order->setSubtotal(100.0);
        $order->setTax(20.0);
        $order->setShipping(10.0);
        $order->setDiscount(5.0);
        $order->setTotal(125.0);
        $order->setCouponCode('SAVE5');
        $order->setBilling(['name' => 'Jane']);
        $order->setShippingAddress(['city' => 'Istanbul']);
        $order->setCustomerNote('Leave at door');
        $order->setPaymentMethod('stripe');
        $order->setTransactionId('txn_123');
        $order->setPaymentToken('tok_abc');
        $order->setPaidAt(new \DateTimeImmutable());
        $order->setCarrier('DHL');
        $order->setTrackingNumber('TRACK001');
        $order->setItems([['product_id' => 1, 'quantity' => 2]]);
        $order->setInventoryProcessed(true);
        $order->setInventoryRestored(false);

        self::assertSame('processing', $order->getStatus());
        self::assertSame('tr', $order->getLocale());
        self::assertSame('EUR', $order->getCurrency());
        self::assertSame(100.0, $order->getSubtotal());
        self::assertSame(20.0, $order->getTax());
        self::assertSame(10.0, $order->getShipping());
        self::assertSame(5.0, $order->getDiscount());
        self::assertSame(125.0, $order->getTotal());
        self::assertSame('SAVE5', $order->getCouponCode());
        self::assertSame('stripe', $order->getPaymentMethod());
        self::assertSame('txn_123', $order->getTransactionId());
        self::assertSame('tok_abc', $order->getPaymentToken());
        self::assertInstanceOf(\DateTimeImmutable::class, $order->getPaidAt());
        self::assertSame('DHL', $order->getCarrier());
        self::assertSame('TRACK001', $order->getTrackingNumber());
        self::assertCount(1, $order->getItems());
        self::assertTrue($order->isInventoryProcessed());
        self::assertFalse($order->isInventoryRestored());
    }

    public function testCalculateTotal(): void
    {
        $order = new Order('ORD-001', []);
        $order->setSubtotal(100.0);
        $order->setTax(18.0);
        $order->setShipping(10.0);
        $order->setDiscount(5.0);
        $order->calculateTotal();

        self::assertSame(123.0, $order->getTotal());
    }

    public function testFromRow(): void
    {
        $order = Order::fromRow([
            'id'                  => '10',
            'order_number'        => 'ORD-100',
            'user_id'             => '3',
            'status'              => 'completed',
            'locale'              => 'tr',
            'currency'            => 'EUR',
            'subtotal'            => '200',
            'tax'                 => '36',
            'shipping'            => '15',
            'discount'            => '10',
            'total'               => '241',
            'coupon_code'         => 'SAVE10',
            'billing'             => '{"name":"Ali"}',
            'shipping_address'    => '{"city":"Istanbul"}',
            'customer_note'       => 'Urgent',
            'payment_method'      => 'stripe',
            'transaction_id'      => 'txn_abc',
            'payment_token'       => 'tok_xyz',
            'carrier'             => 'Yurtici',
            'tracking_number'     => 'TR001',
            'order_type'          => 'membership',
            'plan_id'             => '5',
            'subscription_id'     => 'sub_123',
            'plan_name'           => 'Pro',
            'inventory_processed' => '1',
            'inventory_restored'  => '0',
            'paid_at'             => '2024-07-01 12:00:00',
            'created_at'          => '2024-07-01 10:00:00',
            'updated_at'          => '2024-07-01 13:00:00',
        ], [['product_id' => 1]]);

        self::assertSame(10, $order->getId());
        self::assertSame('ORD-100', $order->getOrderNumber());
        self::assertSame(3, $order->getUserId());
        self::assertSame('completed', $order->getStatus());
        self::assertSame('tr', $order->getLocale());
        self::assertSame('EUR', $order->getCurrency());
        self::assertSame(200.0, $order->getSubtotal());
        self::assertSame(36.0, $order->getTax());
        self::assertSame(15.0, $order->getShipping());
        self::assertSame(10.0, $order->getDiscount());
        self::assertSame(241.0, $order->getTotal());
        self::assertSame('SAVE10', $order->getCouponCode());
        self::assertSame(['name' => 'Ali'], $order->getBilling());
        self::assertSame(['city' => 'Istanbul'], $order->getShippingAddress());
        self::assertSame('Urgent', $order->getCustomerNote());
        self::assertSame('stripe', $order->getPaymentMethod());
        self::assertSame('txn_abc', $order->getTransactionId());
        self::assertSame('tok_xyz', $order->getPaymentToken());
        self::assertSame('Yurtici', $order->getCarrier());
        self::assertSame('TR001', $order->getTrackingNumber());
        self::assertSame('membership', $order->getOrderType());
        self::assertSame(5, $order->getPlanId());
        self::assertSame('sub_123', $order->getSubscriptionId());
        self::assertSame('Pro', $order->getPlanName());
        self::assertTrue($order->isInventoryProcessed());
        self::assertFalse($order->isInventoryRestored());
        self::assertCount(1, $order->getItems());
        self::assertInstanceOf(\DateTimeImmutable::class, $order->getPaidAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $order->getCreatedAt());
    }

    public function testFromRowNullUserId(): void
    {
        $order = Order::fromRow([
            'id'           => '1',
            'order_number' => 'ORD-1',
            'user_id'      => null,
            'billing'      => null,
        ]);

        self::assertNull($order->getUserId());
        self::assertSame([], $order->getBilling());
        self::assertSame('pending', $order->getStatus());
    }

    public function testFromRowInventoryNull(): void
    {
        $order = Order::fromRow([
            'id'                  => '2',
            'order_number'        => 'ORD-1',
            'inventory_processed' => null,
            'inventory_restored'  => null,
        ]);

        self::assertNull($order->isInventoryProcessed());
        self::assertNull($order->isInventoryRestored());
    }
}
