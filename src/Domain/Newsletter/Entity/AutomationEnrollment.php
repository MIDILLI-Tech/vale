<?php

declare(strict_types=1);

namespace ValeApp\Domain\Newsletter\Entity;

final class AutomationEnrollment
{
    private ?int $id = null;
    private int $sequenceId;
    private int $subscriberId;
    private int $nextStep;
    private ?\DateTimeImmutable $nextSendAt;
    private string $status;
    private ?string $lastVariation  = null;
    private ?string $trackingToken  = null;
    private \DateTimeImmutable $enrolledAt;
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        int $sequenceId,
        int $subscriberId,
        int $nextStep,
        ?\DateTimeImmutable $nextSendAt,
        string $status = 'active',
        ?\DateTimeImmutable $enrolledAt = null,
    ) {
        $this->sequenceId   = $sequenceId;
        $this->subscriberId = $subscriberId;
        $this->nextStep     = $nextStep;
        $this->nextSendAt   = $nextSendAt;
        $this->status       = $status;
        $this->enrolledAt   = $enrolledAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getSequenceId(): int
    {
        return $this->sequenceId;
    }
    public function getSubscriberId(): int
    {
        return $this->subscriberId;
    }
    public function getNextStep(): int
    {
        return $this->nextStep;
    }
    public function getNextSendAt(): ?\DateTimeImmutable
    {
        return $this->nextSendAt;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getEnrolledAt(): \DateTimeImmutable
    {
        return $this->enrolledAt;
    }
    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
    public function getLastVariation(): ?string
    {
        return $this->lastVariation;
    }
    public function setLastVariation(?string $v): void
    {
        $this->lastVariation = $v;
    }
    public function getTrackingToken(): ?string
    {
        return $this->trackingToken;
    }
    public function setTrackingToken(?string $t): void
    {
        $this->trackingToken = $t;
    }
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * Advance to next step: increment index and schedule next send at now + delayMinutes.
     */
    public function advance(AutomationStep $nextStep): void
    {
        $this->nextStep   = $nextStep->getSortOrder();
        $this->nextSendAt = (new \DateTimeImmutable())->modify('+' . $nextStep->getDelayMinutes() . ' minutes');
    }

    public function complete(): void
    {
        $this->status      = 'completed';
        $this->nextSendAt  = null;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function cancel(): void
    {
        $this->status = 'cancelled';
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $e = new self(
            sequenceId:   (int) $row['sequence_id'],
            subscriberId: (int) $row['subscriber_id'],
            nextStep:     (int) ($row['next_step'] ?? 0),
            nextSendAt:   !empty($row['next_send_at']) ? new \DateTimeImmutable($row['next_send_at']) : null,
            status:       $row['status'] ?? 'active',
            enrolledAt:   !empty($row['enrolled_at']) ? new \DateTimeImmutable($row['enrolled_at']) : null,
        );
        $e->id             = (int) $row['id'];
        $e->lastVariation  = $row['last_variation']  ?? null;
        $e->trackingToken  = $row['tracking_token']  ?? null;
        if (!empty($row['completed_at'])) {
            $e->completedAt = new \DateTimeImmutable($row['completed_at']);
        }
        return $e;
    }
}
