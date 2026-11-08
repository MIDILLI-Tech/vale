<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Membership\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Membership\Entity\MembershipPlan;

final class MembershipPlanTest extends TestCase
{
    public function testConstructorAndDefaults(): void
    {
        $plan = new MembershipPlan(
            name: 'Pro',
            slug: 'pro',
            description: 'Pro plan',
            price: '9.99',
            interval: 'monthly',
            gateway: 'stripe',
            tag: 'pro-member',
            trialDays: 14,
            trialTag: 'trial-user',
            trialPrice: '1.00',
            sortOrder: 1,
            isActive: true,
        );

        self::assertSame('Pro', $plan->getName());
        self::assertSame('pro', $plan->getSlug());
        self::assertSame('Pro plan', $plan->getDescription());
        self::assertSame('9.99', $plan->getPrice());
        self::assertSame('monthly', $plan->getInterval());
        self::assertSame('stripe', $plan->getGateway());
        self::assertSame('pro-member', $plan->getTag());
        self::assertSame(14, $plan->getTrialDays());
        self::assertSame('trial-user', $plan->getTrialTag());
        self::assertSame('1.00', $plan->getTrialPrice());
        self::assertSame(1, $plan->getSortOrder());
        self::assertTrue($plan->isActive());
    }

    public function testSetters(): void
    {
        $plan = new MembershipPlan('X', 'x', null, '0', 'monthly', 'stripe', 'tag', 0, null);

        $plan->setName('Basic');
        $plan->setSlug('basic');
        $plan->setDescription('Basic plan');
        $plan->setPrice('4.99');
        $plan->setInterval('yearly');
        $plan->setGateway('paypal');
        $plan->setTag('basic-member');
        $plan->setTrialDays(7);
        $plan->setTrialTag('trial');
        $plan->setTrialPrice('0.99');
        $plan->setSortOrder(2);
        $plan->setIsActive(false);

        self::assertSame('Basic', $plan->getName());
        self::assertSame('basic', $plan->getSlug());
        self::assertSame('Basic plan', $plan->getDescription());
        self::assertSame('4.99', $plan->getPrice());
        self::assertSame('yearly', $plan->getInterval());
        self::assertSame('paypal', $plan->getGateway());
        self::assertSame('basic-member', $plan->getTag());
        self::assertSame(7, $plan->getTrialDays());
        self::assertSame('trial', $plan->getTrialTag());
        self::assertSame('0.99', $plan->getTrialPrice());
        self::assertSame(2, $plan->getSortOrder());
        self::assertFalse($plan->isActive());
    }

    public function testIntervalLabel(): void
    {
        self::assertSame('One Time', $this->plan('one_time')->intervalLabel());
        self::assertSame('Per Day', $this->plan('daily')->intervalLabel());
        self::assertSame('Per Week', $this->plan('weekly')->intervalLabel());
        self::assertSame('Per Month', $this->plan('monthly')->intervalLabel());
        self::assertSame('Per Year', $this->plan('yearly')->intervalLabel());
        self::assertSame('Custom', $this->plan('custom')->intervalLabel());
    }

    public function testFromRow(): void
    {
        $plan = MembershipPlan::fromRow([
            'id'             => '3',
            'name'           => 'Enterprise',
            'slug'           => 'enterprise',
            'description'    => 'For teams',
            'price'          => '99.00',
            'plan_interval'  => 'monthly',
            'gateway'        => 'stripe',
            'tag'            => 'enterprise-member',
            'trial_days'     => '30',
            'trial_tag'      => 'trial-ent',
            'trial_price'    => '5.00',
            'sort_order'     => '2',
            'is_active'      => '1',
            'created_at'     => '2024-01-01 00:00:00',
            'updated_at'     => '2024-06-01 00:00:00',
        ]);

        self::assertSame(3, $plan->getId());
        self::assertSame('Enterprise', $plan->getName());
        self::assertSame('enterprise', $plan->getSlug());
        self::assertSame('For teams', $plan->getDescription());
        self::assertSame('99.00', $plan->getPrice());
        self::assertSame('monthly', $plan->getInterval());
        self::assertSame('stripe', $plan->getGateway());
        self::assertSame('enterprise-member', $plan->getTag());
        self::assertSame(30, $plan->getTrialDays());
        self::assertSame('trial-ent', $plan->getTrialTag());
        self::assertSame('5.00', $plan->getTrialPrice());
        self::assertSame(2, $plan->getSortOrder());
        self::assertTrue($plan->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $plan->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $plan->getUpdatedAt());
    }

    public function testFromRowNullables(): void
    {
        $plan = MembershipPlan::fromRow([
            'id'            => '1',
            'name'          => 'Free',
            'slug'          => 'free',
            'description'   => null,
            'price'         => '0',
            'plan_interval' => 'one_time',
            'gateway'       => 'manual',
            'tag'           => 'free-member',
            'trial_days'    => '0',
            'trial_tag'     => null,
        ]);

        self::assertNull($plan->getDescription());
        self::assertNull($plan->getTrialTag());
        self::assertSame('0.00', $plan->getTrialPrice());
        self::assertSame(0, $plan->getSortOrder());
        self::assertTrue($plan->isActive());
    }

    private function plan(string $interval): MembershipPlan
    {
        return new MembershipPlan('X', 'x', null, '0', $interval, 'stripe', 'tag', 0, null);
    }
}
