<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Payment;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Payment\PayPalGateway;

final class PayPalWebhookMappingTest extends TestCase
{
    private function handle(string $eventType): ?array
    {
        $gw = new PayPalGateway('cid', 'csecret', true, '', '', 'WHID');
        return $gw->handleWebhook((string) json_encode([
            'event_type' => $eventType,
            'resource'   => ['id' => 'I-ABC123'],
        ]), 'sig');
    }

    public function testPaymentFailedMapsToPastDue(): void
    {
        $event = $this->handle('BILLING.SUBSCRIPTION.PAYMENT.FAILED');

        self::assertNotNull($event, 'PAYMENT.FAILED must not fall through to active');
        self::assertSame('subscription_updated', $event['event']);
        self::assertSame('past_due', $event['subscription_status']);
    }

    public function testActivatedMapsToActive(): void
    {
        self::assertSame('active', $this->handle('BILLING.SUBSCRIPTION.ACTIVATED')['subscription_status']);
    }

    public function testCancelledMapsToCanceled(): void
    {
        self::assertSame('canceled', $this->handle('BILLING.SUBSCRIPTION.CANCELLED')['subscription_status']);
    }

    public function testSuspendedMapsToPastDue(): void
    {
        self::assertSame('past_due', $this->handle('BILLING.SUBSCRIPTION.SUSPENDED')['subscription_status']);
    }

    public function testExpiredMapsToCanceled(): void
    {
        self::assertSame('canceled', $this->handle('BILLING.SUBSCRIPTION.EXPIRED')['subscription_status']);
    }

    public function testUnknownSubscriptionEventIsIgnored(): void
    {
        self::assertNull($this->handle('BILLING.SUBSCRIPTION.SOMETHING.NEW'));
    }
}
