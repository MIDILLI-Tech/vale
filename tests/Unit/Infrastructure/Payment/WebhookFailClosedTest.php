<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Payment;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Payment\CheckoutComGateway;
use ValeApp\Infrastructure\Payment\PayPalGateway;
use ValeApp\Infrastructure\Payment\TwoCheckoutGateway;

final class WebhookFailClosedTest extends TestCase
{
    public function testCheckoutComRejectsWhenSecretNotConfigured(): void
    {
        $gw = new CheckoutComGateway('sk_test', '');

        self::assertNull($gw->handleWebhook('{"type":"payment_captured","data":{"reference":"1"}}', 'anysig'));
    }

    public function testCheckoutComRejectsWhenSignatureMissing(): void
    {
        $gw = new CheckoutComGateway('sk_test', 'whsec');

        self::assertNull($gw->handleWebhook('{"type":"payment_captured","data":{"reference":"1"}}', ''));
    }

    public function testCheckoutComRejectsInvalidSignature(): void
    {
        $gw = new CheckoutComGateway('sk_test', 'whsec');

        self::assertNull($gw->handleWebhook('{"type":"payment_captured","data":{"reference":"1"}}', 'badsig'));
    }

    public function testCheckoutComAcceptsValidSignature(): void
    {
        $payload   = '{"type":"payment_captured","data":{"reference":"1001","id":"pay_1"}}';
        $signature = base64_encode(hash_hmac('sha256', $payload, 'whsec', true));
        $gw        = new CheckoutComGateway('sk_test', 'whsec');

        $event = $gw->handleWebhook($payload, $signature);

        self::assertNotNull($event);
        self::assertSame('payment_succeeded', $event['event']);
        self::assertSame('1001', $event['order_id']);
    }

    public function testTwoCheckoutRejectsWhenSecretWordNotConfigured(): void
    {
        $gw      = new TwoCheckoutGateway('MERCHANT', 'apisecret', '');
        $payload = http_build_query(['message_type' => 'ORDER_CREATED', 'refno' => 'R1', 'order_number' => '1002']);

        self::assertNull($gw->handleWebhook($payload, ''));
    }

    public function testTwoCheckoutRejectsMissingHash(): void
    {
        $gw      = new TwoCheckoutGateway('MERCHANT', 'apisecret', 'secret');
        $payload = http_build_query(['message_type' => 'ORDER_CREATED', 'refno' => 'R1', 'order_number' => '1002']);

        self::assertNull($gw->handleWebhook($payload, ''));
    }

    public function testTwoCheckoutRejectsWrongHash(): void
    {
        $gw      = new TwoCheckoutGateway('MERCHANT', 'apisecret', 'secret');
        $payload = http_build_query([
            'message_type' => 'ORDER_CREATED',
            'refno'        => 'R1',
            'order_number' => '1002',
            'total'        => '10.00',
            'md5_hash'     => strtoupper(md5('wrong')),
        ]);

        self::assertNull($gw->handleWebhook($payload, ''));
    }

    public function testTwoCheckoutAcceptsValidHash(): void
    {
        $secret  = 'secret';
        $gw      = new TwoCheckoutGateway('MERCHANT', 'apisecret', $secret);
        $hash    = strtoupper(md5($secret . 'R1' . '1002' . '10.00'));
        $payload = http_build_query([
            'message_type' => 'ORDER_CREATED',
            'refno'        => 'R1',
            'order_number' => '1002',
            'total'        => '10.00',
            'md5_hash'     => $hash,
        ]);

        $event = $gw->handleWebhook($payload, '');

        self::assertNotNull($event);
        self::assertSame('payment_succeeded', $event['event']);
        self::assertSame('1002', $event['order_id']);
    }

    public function testPayPalVerifyFailsClosedWithoutWebhookId(): void
    {
        $gw = new PayPalGateway('cid', 'csecret', true);

        self::assertFalse($gw->verifyWebhook([
            'auth_algo'         => 'SHA256withRSA',
            'cert_url'          => 'https://api.paypal.com/cert',
            'transmission_id'   => 't1',
            'transmission_sig'  => 'sig',
            'transmission_time' => '2026-01-01T00:00:00Z',
        ], '{"event_type":"BILLING.SUBSCRIPTION.ACTIVATED"}'));
    }

    public function testPayPalVerifyFailsClosedWithMissingHeaders(): void
    {
        $gw = new PayPalGateway('cid', 'csecret', true, '', '', 'WHID');

        self::assertFalse($gw->verifyWebhook([], '{}'));
        self::assertFalse($gw->verifyWebhook(['auth_algo' => 'SHA256withRSA'], '{}'));
    }
}
