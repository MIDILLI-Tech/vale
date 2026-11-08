<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Newsletter\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Newsletter\Entity\AutomationSequence;

final class AutomationSequenceTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $seq = new AutomationSequence('Welcome Series');

        self::assertSame('Welcome Series', $seq->getName());
        self::assertSame('subscribe', $seq->getTriggerType());
        self::assertNull($seq->getTriggerFormId());
        self::assertNull($seq->getTriggerTag());
        self::assertSame('any', $seq->getTriggerTagMatch());
        self::assertSame('active', $seq->getStatus());
        self::assertTrue($seq->isActive());
        self::assertSame(AutomationSequence::TRACKING_ALL, $seq->getTrackingEnabled());
        self::assertNull($seq->getId());
        self::assertNull($seq->getCreatedAt());
    }

    public function testTrackingBitmask(): void
    {
        $seq = new AutomationSequence('S');

        self::assertTrue($seq->hasOpenTracking());
        self::assertTrue($seq->hasClickTracking());

        $seq->setTrackingEnabled(AutomationSequence::TRACKING_NONE);
        self::assertFalse($seq->hasOpenTracking());
        self::assertFalse($seq->hasClickTracking());

        $seq->setTrackingEnabled(AutomationSequence::TRACKING_OPEN);
        self::assertTrue($seq->hasOpenTracking());
        self::assertFalse($seq->hasClickTracking());
    }

    public function testTrackingClamp(): void
    {
        $seq = new AutomationSequence('S');
        $seq->setTrackingEnabled(5);

        self::assertSame(3, $seq->getTrackingEnabled());
    }

    public function testActivateAndPause(): void
    {
        $seq = new AutomationSequence('S');
        $seq->pause();
        self::assertSame('paused', $seq->getStatus());
        self::assertFalse($seq->isActive());

        $seq->activate();
        self::assertSame('active', $seq->getStatus());
        self::assertTrue($seq->isActive());
    }

    public function testGetTriggerTags(): void
    {
        $seq = new AutomationSequence('S', 'tag', null, 'php, laravel, symfony');
        self::assertSame(['php', 'laravel', 'symfony'], $seq->getTriggerTags());

        $seq2 = new AutomationSequence('S', 'tag', null, null);
        self::assertSame([], $seq2->getTriggerTags());

        $seq3 = new AutomationSequence('S', 'tag', null, '  , , ');
        self::assertSame([], $seq3->getTriggerTags());
    }

    public function testSetters(): void
    {
        $seq = new AutomationSequence('S');
        $seq->setId(10);
        $seq->setName('New Name');
        $seq->setTriggerType('tag');
        $seq->setTriggerFormId(5);
        $seq->setTriggerTag('vip');
        $seq->setTriggerTagMatch('all');

        self::assertSame(10, $seq->getId());
        self::assertSame('New Name', $seq->getName());
        self::assertSame('tag', $seq->getTriggerType());
        self::assertSame(5, $seq->getTriggerFormId());
        self::assertSame('vip', $seq->getTriggerTag());
        self::assertSame('all', $seq->getTriggerTagMatch());
    }

    public function testFromRow(): void
    {
        $seq = AutomationSequence::fromRow([
            'id'                => '6',
            'name'              => 'Onboarding',
            'trigger_type'      => 'subscribe',
            'trigger_form_id'   => '3',
            'trigger_tag'       => 'leads',
            'trigger_tag_match' => 'any',
            'status'            => 'paused',
            'tracking_enabled'  => '1',
            'created_at'        => '2024-01-01 00:00:00',
            'updated_at'        => '2024-06-01 00:00:00',
        ]);

        self::assertSame(6, $seq->getId());
        self::assertSame('Onboarding', $seq->getName());
        self::assertSame('subscribe', $seq->getTriggerType());
        self::assertSame(3, $seq->getTriggerFormId());
        self::assertSame('leads', $seq->getTriggerTag());
        self::assertSame('paused', $seq->getStatus());
        self::assertSame(1, $seq->getTrackingEnabled());
        self::assertInstanceOf(\DateTimeImmutable::class, $seq->getCreatedAt());
    }

    public function testConstants(): void
    {
        self::assertSame(0, AutomationSequence::TRACKING_NONE);
        self::assertSame(1, AutomationSequence::TRACKING_OPEN);
        self::assertSame(2, AutomationSequence::TRACKING_CLICK);
        self::assertSame(3, AutomationSequence::TRACKING_ALL);
    }
}
