<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Newsletter;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Newsletter\Entity\AutomationEnrollment;
use ValeApp\Domain\Newsletter\Entity\AutomationStep;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\AutomationRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;

class AutomationServiceTest extends TestCase
{
    private $automations;
    private $subscribers;
    private $mail;
    private $orders;
    private $service;

    protected function setUp(): void
    {
        $this->automations = $this->createStub(AutomationRepositoryInterface::class);
        $this->subscribers = $this->createStub(SubscriberRepositoryInterface::class);
        $this->mail        = $this->createStub(MailInterface::class);
        $this->orders      = $this->createStub(OrderRepositoryInterface::class);
        $this->service     = $this->buildService();
    }

    private function buildService(): AutomationService
    {
        return new AutomationService(
            $this->automations,
            $this->subscribers,
            $this->mail,
            $this->orders,
            'http://localhost'
        );
    }

    public function testProcessDueAppliesSegmentation(): void
    {
        $subscriber = new Subscriber('test@example.com', 'token');
        $subscriber->setId(1);
        $subscriber->setUserId(100);
        $subscriber->setStatus('active');

        $enrollment = new AutomationEnrollment(1, 1, 0, new \DateTimeImmutable('-1 hour'));
        $enrollment->setId(1);

        $step = new AutomationStep(1, 0, 60, 'Subject', 'Body');
        $step->setMinOrdersFilter(5);

        $this->automations = $this->createMock(AutomationRepositoryInterface::class);
        $this->mail        = $this->createMock(MailInterface::class);
        $this->orders      = $this->createMock(OrderRepositoryInterface::class);
        $this->service     = $this->buildService();

        $this->automations->method('findDueEnrollments')->willReturn([$enrollment]);
        $this->subscribers->method('findById')->willReturn($subscriber);
        $this->automations->method('findStepsBySequenceId')->willReturn([$step]);

        $this->orders->expects($this->once())
            ->method('countByUserId')
            ->with(100)
            ->willReturn(2); // Less than 5

        $this->mail->expects($this->never())->method('send');
        $this->automations->expects($this->once())->method('updateEnrollment')
            ->with($this->callback(fn ($e) => $enrollment->getStatus() === 'completed'));

        $this->service->processDue();
    }

    public function testProcessDueUsesVariationB(): void
    {
        $subscriber = new Subscriber('test@example.com', 'token');
        $subscriber->setId(1);
        $subscriber->setStatus('active');

        $enrollment = new AutomationEnrollment(1, 1, 0, new \DateTimeImmutable('-1 hour'));
        $enrollment->setId(1);

        $step = new AutomationStep(1, 0, 60, 'Subject A', 'Body A');
        $step->setSubjectB('Subject B');
        $step->setAbTestEnabled(true);
        $step->setVariationBWeight(100); // Force B

        $this->mail    = $this->createMock(MailInterface::class);
        $this->service = $this->buildService();

        $this->automations->method('findDueEnrollments')->willReturn([$enrollment]);
        $this->subscribers->method('findById')->willReturn($subscriber);
        $this->automations->method('findStepsBySequenceId')->willReturn([$step]);

        $this->mail->expects($this->once())
            ->method('send')
            ->with($this->callback(fn ($msg) => $msg->subject === 'Subject B'));

        $this->service->processDue();
    }
}
