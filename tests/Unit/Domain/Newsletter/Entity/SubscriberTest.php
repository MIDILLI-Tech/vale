<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Newsletter\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Newsletter\Entity\Subscriber;

final class SubscriberTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $sub = new Subscriber('a@b.com', 'tok-123');

        self::assertSame('a@b.com', $sub->getEmail());
        self::assertSame('tok-123', $sub->getToken());
        self::assertNull($sub->getName());
        self::assertSame('pending', $sub->getStatus());
        self::assertNull($sub->getIpAddress());
        self::assertNull($sub->getSourceFormId());
        self::assertNull($sub->getSubscribedAt());
        self::assertNull($sub->getConfirmedAt());
        self::assertNull($sub->getSubscriptionExpiresAt());
        self::assertNull($sub->getSubscriptionPlanName());
        self::assertNull($sub->getSubscriptionStatus());
        self::assertNull($sub->getUserId());
        self::assertSame('free', $sub->getTier());
        self::assertSame([], $sub->getTags());
        self::assertFalse($sub->isActive());
    }

    public function testConfirm(): void
    {
        $sub = new Subscriber('a@b.com', 'tok');
        $sub->confirm();

        self::assertSame('active', $sub->getStatus());
        self::assertTrue($sub->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $sub->getConfirmedAt());
    }

    public function testUnsubscribe(): void
    {
        $sub = new Subscriber('a@b.com', 'tok');
        $sub->unsubscribe();

        self::assertSame('unsubscribed', $sub->getStatus());
        self::assertFalse($sub->isActive());
    }

    public function testSetters(): void
    {
        $sub = new Subscriber('a@b.com', 'tok');

        $sub->setName('John');
        $sub->setStatus('active');
        $sub->setIpAddress('127.0.0.1');
        $sub->setSourceFormId(5);
        $sub->setSubscribedAt(new \DateTimeImmutable('2024-01-01'));
        $sub->setUserId(10);
        $sub->setTier('premium');
        $sub->setTags(['vip', 'beta']);

        self::assertSame('John', $sub->getName());
        self::assertSame('active', $sub->getStatus());
        self::assertSame('127.0.0.1', $sub->getIpAddress());
        self::assertSame(5, $sub->getSourceFormId());
        self::assertSame(10, $sub->getUserId());
        self::assertSame('premium', $sub->getTier());
        self::assertSame(['vip', 'beta'], $sub->getTags());
    }

    public function testFromRow(): void
    {
        $sub = Subscriber::fromRow([
            'id'                       => '8',
            'email'                    => 'test@test.com',
            'name'                     => 'Jane',
            'token'                    => 'tok-xyz',
            'status'                   => 'active',
            'ip_address'               => '10.0.0.1',
            'source_form_id'           => '3',
            'user_id'                  => '7',
            'tier'                     => 'premium',
            'subscribed_at'            => '2024-01-01 00:00:00',
            'confirmed_at'             => '2024-01-02 00:00:00',
            'subscription_expires_at'  => '2025-01-01 00:00:00',
            'subscription_plan_name'   => 'Pro',
            'subscription_status'      => 'active',
            'created_at'               => '2024-01-01 00:00:00',
        ], ['vip', 'beta']);

        self::assertSame(8, $sub->getId());
        self::assertSame('test@test.com', $sub->getEmail());
        self::assertSame('Jane', $sub->getName());
        self::assertSame('active', $sub->getStatus());
        self::assertSame('10.0.0.1', $sub->getIpAddress());
        self::assertSame(3, $sub->getSourceFormId());
        self::assertSame(7, $sub->getUserId());
        self::assertSame('premium', $sub->getTier());
        self::assertSame(['vip', 'beta'], $sub->getTags());
        self::assertSame('Pro', $sub->getSubscriptionPlanName());
        self::assertSame('active', $sub->getSubscriptionStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $sub->getSubscribedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $sub->getConfirmedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $sub->getSubscriptionExpiresAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $sub->getCreatedAt());
    }

    public function testFromRowDefaults(): void
    {
        $sub = Subscriber::fromRow([
            'id'    => '1',
            'email' => 'x@y.com',
            'token' => 't',
        ]);

        self::assertSame('pending', $sub->getStatus());
        self::assertSame('free', $sub->getTier());
        self::assertSame([], $sub->getTags());
        self::assertNull($sub->getSubscriptionPlanName());
        self::assertNull($sub->getSubscriptionStatus());
    }
}
