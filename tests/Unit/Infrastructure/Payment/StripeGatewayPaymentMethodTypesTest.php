<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Payment;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Infrastructure\Payment\StripeGateway;

final class StripeGatewayPaymentMethodTypesTest extends TestCase
{
    private function order(): Order
    {
        $order = new Order('ORD-1', [
            'first_name' => 'Volkan',
            'last_name'  => 'Gezer',
            'email'      => 'v@example.com',
            'address'    => 'Erlenbacher Str., 114',
            'city'       => 'Kaiserslautern',
            'state'      => 'RLP',
            'postcode'   => '67659',
            'country'    => 'DE',
        ]);
        $order->setTotal(0.01);
        $order->setCurrency('EUR');

        return $order;
    }

    public function testExplicitTypesReplaceAutomaticMethods(): void
    {
        $params = (new StripeGateway('sk_test_dummy', '', ['card']))->buildPaymentIntentParams($this->order());

        self::assertSame(['card'], $params['payment_method_types']);
        self::assertArrayNotHasKey('automatic_payment_methods', $params);
        self::assertSame('v@example.com', $params['receipt_email']);
        self::assertSame('DE', $params['shipping']['address']['country']);
    }

    public function testEmptyTypesKeepAutomaticMethods(): void
    {
        $params = (new StripeGateway('sk_test_dummy'))->buildPaymentIntentParams($this->order());

        self::assertSame(['enabled' => 'true'], $params['automatic_payment_methods']);
        self::assertArrayNotHasKey('payment_method_types', $params);
    }
}
