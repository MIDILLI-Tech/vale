<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Newsletter\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Newsletter\Entity\AutomationEnrollment;
use ValeApp\Domain\Newsletter\Entity\AutomationStep;

final class AutomationEnrollmentTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $next = new \DateTimeImmutable('+1 hour');
        $e    = new AutomationEnrollment(1, 2, 0, $next);

        self::assertSame(1, $e->getSequenceId());
        self::assertSame(2, $e->getSubscriberId());
        self::assertSame(0, $e->getNextStep());
        self::assertSame($next, $e->getNextSendAt());
        self::assertSame('active', $e->getStatus());
        self::assertTrue($e->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $e->getEnrolledAt());
        self::assertNull($e->getCompletedAt());
        self::assertNull($e->getLastVariation());
        self::assertNull($e->getTrackingToken());
    }

    public function testAdvanceToNextStep(): void
    {
        $step = new AutomationStep(1, 2, 60, 'Subject', '<p>Body</p>');
        $e    = new AutomationEnrollment(1, 1, 0, null);

        $e->advance($step);

        self::assertSame(2, $e->getNextStep());
        self::assertNotNull($e->getNextSendAt());
        self::assertTrue($e->getNextSendAt() > new \DateTimeImmutable());
    }

    public function testComplete(): void
    {
        $e = new AutomationEnrollment(1, 1, 0, null);
        $e->complete();

        self::assertSame('completed', $e->getStatus());
        self::assertFalse($e->isActive());
        self::assertNull($e->getNextSendAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $e->getCompletedAt());
    }

    public function testCancel(): void
    {
        $e = new AutomationEnrollment(1, 1, 0, null);
        $e->cancel();

        self::assertSame('cancelled', $e->getStatus());
        self::assertFalse($e->isActive());
    }

    public function testSetters(): void
    {
        $e = new AutomationEnrollment(1, 1, 0, null);
        $e->setId(99);
        $e->setLastVariation('B');
        $e->setTrackingToken('abc-123');

        self::assertSame(99, $e->getId());
        self::assertSame('B', $e->getLastVariation());
        self::assertSame('abc-123', $e->getTrackingToken());
    }

    public function testFromRow(): void
    {
        $e = AutomationEnrollment::fromRow([
            'id'               => '10',
            'sequence_id'      => '3',
            'subscriber_id'    => '7',
            'next_step'        => '2',
            'next_send_at'     => '2024-08-01 10:00:00',
            'status'           => 'active',
            'enrolled_at'      => '2024-07-01 09:00:00',
            'completed_at'     => null,
            'last_variation'   => 'A',
            'tracking_token'   => 'tok-xyz',
        ]);

        self::assertSame(10, $e->getId());
        self::assertSame(3, $e->getSequenceId());
        self::assertSame(7, $e->getSubscriberId());
        self::assertSame(2, $e->getNextStep());
        self::assertSame('active', $e->getStatus());
        self::assertSame('A', $e->getLastVariation());
        self::assertSame('tok-xyz', $e->getTrackingToken());
        self::assertInstanceOf(\DateTimeImmutable::class, $e->getEnrolledAt());
        self::assertNull($e->getCompletedAt());
    }

    public function testFromRowWithCompletedAt(): void
    {
        $e = AutomationEnrollment::fromRow([
            'id'             => '1',
            'sequence_id'    => '1',
            'subscriber_id'  => '1',
            'status'         => 'completed',
            'completed_at'   => '2024-09-01 12:00:00',
        ]);

        self::assertSame('completed', $e->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $e->getCompletedAt());
    }
}
