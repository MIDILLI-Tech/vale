<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Payment;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Infrastructure\Payment\StripeGateway;

final class StripeGatewayTest extends TestCase
{
    public function testGetNameReturnsStripe(): void
    {
        $gateway = new StripeGateway('sk_test_dummy');
        $this->assertSame('stripe', $gateway->getName());
    }

    public function testCreatePaymentThrowsExceptionOnInvalidKey(): void
    {
        $gateway = new StripeGateway('sk_test_invalid_key');
        $order   = new Order('ORD-1001', [
            'first_name' => 'Hans',
            'last_name'  => 'Müller',
            'email'      => 'hans@example.de',
            'address'    => 'Berliner Str. 12',
            'city'       => 'Berlin',
            'state'      => 'Berlin',
            'postcode'   => '10115',
            'country'    => 'DE',
        ]);
        $order->setTotal(50.00);
        $order->setCurrency('EUR');

        $this->expectException(\RuntimeException::class);
        $gateway->createPayment($order);
    }

    public function testCreatePaymentWithShippingAddress(): void
    {
        $gateway = new StripeGateway('sk_test_invalid_key');
        $order   = new Order('ORD-1002', [
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => 'john@example.com',
            'address'    => '123 Main St',
            'city'       => 'Munich',
            'state'      => 'Bavaria',
            'postcode'   => '80331',
            'country'    => 'DE',
        ]);
        $order->setShippingAddress([
            'first_name' => 'Max',
            'last_name'  => 'Mustermann',
            'address'    => 'Hauptstr. 1',
            'city'       => 'Berlin',
            'state'      => 'Berlin',
            'postcode'   => '10115',
            'country'    => 'DE',
        ]);
        $order->setTotal(100.00);
        $order->setCurrency('EUR');

        $this->expectException(\RuntimeException::class);
        $gateway->createPayment($order);
    }
}
