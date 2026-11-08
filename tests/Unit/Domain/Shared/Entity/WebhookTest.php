<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shared\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\Entity\Webhook;

final class WebhookTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $wh = new Webhook('Order Hook', 'https://example.com/hook');

        self::assertSame('Order Hook', $wh->getName());
        self::assertSame('https://example.com/hook', $wh->getUrl());
        self::assertNull($wh->getSecret());
        self::assertSame('POST', $wh->getMethod());
        self::assertNull($wh->getBodyTemplate());
        self::assertSame([], $wh->getHeaders());
        self::assertSame([], $wh->getEvents());
        self::assertTrue($wh->isActive());
        self::assertNull($wh->getLastDeliveryAt());
        self::assertNull($wh->getLastDeliveryStatus());
        self::assertNull($wh->getLastDeliveryMs());
    }

    public function testConstructorWithSecret(): void
    {
        $wh = new Webhook('Hook', 'https://x.com', 'my-secret');

        self::assertSame('my-secret', $wh->getSecret());
    }

    public function testSetters(): void
    {
        $wh = new Webhook('Hook', 'https://x.com');

        $wh->setName('New Hook');
        $wh->setUrl('https://new.com');
        $wh->setMethod('put');
        $wh->setBodyTemplate('{ "event": "{{event}}" }');
        $wh->setSecret('s3cret');
        $wh->setHeaders(['X-Token' => 'abc']);
        $wh->setEvents(['order.paid', 'post.published']);
        $wh->setActive(false);

        self::assertSame('New Hook', $wh->getName());
        self::assertSame('https://new.com', $wh->getUrl());
        self::assertSame('PUT', $wh->getMethod());
        self::assertSame('{ "event": "{{event}}" }', $wh->getBodyTemplate());
        self::assertSame('s3cret', $wh->getSecret());
        self::assertSame(['X-Token' => 'abc'], $wh->getHeaders());
        self::assertSame(['order.paid', 'post.published'], $wh->getEvents());
        self::assertFalse($wh->isActive());
    }

    public function testEmptySecretAndTemplateTreatedAsNull(): void
    {
        $wh = new Webhook('H', 'https://x.com', '');
        self::assertNull($wh->getSecret());

        $wh->setBodyTemplate('');
        self::assertNull($wh->getBodyTemplate());

        $wh->setSecret('');
        self::assertNull($wh->getSecret());
    }

    public function testRecordDelivery(): void
    {
        $wh = new Webhook('H', 'https://x.com');
        $wh->recordDelivery('ok', 150);

        self::assertSame('ok', $wh->getLastDeliveryStatus());
        self::assertSame(150, $wh->getLastDeliveryMs());
        self::assertInstanceOf(\DateTimeImmutable::class, $wh->getLastDeliveryAt());
    }

    public function testHasEvent(): void
    {
        $wh = new Webhook('H', 'https://x.com');
        $wh->setEvents(['order.paid', 'user.registered']);

        self::assertTrue($wh->hasEvent('order.paid'));
        self::assertTrue($wh->hasEvent('user.registered'));
        self::assertFalse($wh->hasEvent('post.published'));
    }

    public function testFromRow(): void
    {
        $wh = Webhook::fromRow([
            'id'                   => '3',
            'name'                 => 'Slack',
            'url'                  => 'https://hooks.slack.com/xxx',
            'secret'               => 'whsec_123',
            'method'               => 'post',
            'body_template'        => null,
            'events'               => '["order.paid"]',
            'headers'              => '{"X-LUMI":"y"}',
            'is_active'            => '1',
            'last_delivery_at'     => '2024-07-01 12:00:00',
            'last_delivery_status' => 'ok',
            'last_delivery_ms'     => '200',
            'created_at'           => '2024-06-01 00:00:00',
            'updated_at'           => '2024-07-01 12:00:00',
        ]);

        self::assertSame(3, $wh->getId());
        self::assertSame('Slack', $wh->getName());
        self::assertSame('https://hooks.slack.com/xxx', $wh->getUrl());
        self::assertSame('whsec_123', $wh->getSecret());
        self::assertSame('POST', $wh->getMethod());
        self::assertSame(['order.paid'], $wh->getEvents());
        self::assertSame(['X-LUMI' => 'y'], $wh->getHeaders());
        self::assertTrue($wh->isActive());
        self::assertSame('ok', $wh->getLastDeliveryStatus());
        self::assertSame(200, $wh->getLastDeliveryMs());
        self::assertInstanceOf(\DateTimeImmutable::class, $wh->getCreatedAt());
    }

    public function testFromRowEmptyEventsAndHeaders(): void
    {
        $wh = Webhook::fromRow([
            'id'            => '1',
            'name'          => 'H',
            'url'           => 'https://x.com',
            'body_template' => null,
            'events'        => null,
            'headers'       => null,
        ]);

        self::assertSame([], $wh->getEvents());
        self::assertSame([], $wh->getHeaders());
    }

    public function testFromRowEmptyBodyTemplate(): void
    {
        $wh = Webhook::fromRow([
            'id'            => '1',
            'name'          => 'H',
            'url'           => 'https://x.com',
            'body_template' => '',
        ]);

        self::assertNull($wh->getBodyTemplate());
    }
}
