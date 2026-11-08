<?php

declare(strict_types=1);

namespace ValeApp\Domain\Newsletter\Repository;

use ValeApp\Domain\Newsletter\Entity\AutomationEnrollment;
use ValeApp\Domain\Newsletter\Entity\AutomationSequence;
use ValeApp\Domain\Newsletter\Entity\AutomationStep;

interface AutomationRepositoryInterface
{
    // ── Sequences ──────────────────────────────────────────────────

    /** @return AutomationSequence[] */
    public function findAllSequences(): array;

    public function findSequenceById(int $id): ?AutomationSequence;

    public function saveSequence(AutomationSequence $sequence): AutomationSequence;

    public function deleteSequence(int $id): void;

    // ── Steps ──────────────────────────────────────────────────────

    /**
     * All steps for a sequence, ordered by sort_order ASC.
     * @return AutomationStep[]
     */
    public function findStepsBySequenceId(int $sequenceId): array;

    public function saveStep(AutomationStep $step): AutomationStep;

    public function deleteStep(int $id): void;

    /** Delete all steps belonging to a sequence (before replacing them). */
    public function deleteStepsBySequenceId(int $sequenceId): void;

    // ── Enrollments ────────────────────────────────────────────────

    /**
     * Enroll a subscriber in a sequence (idempotent — ignore if already enrolled).
     */
    public function enroll(int $sequenceId, int $subscriberId, \DateTimeImmutable $nextSendAt): void;

    /**
     * Enrollments whose next_send_at <= now AND status = active, with subscriber + sequence info.
     * @return AutomationEnrollment[]
     */
    public function findDueEnrollments(int $limit): array;

    /** Cheap COUNT for quick checks. */
    public function countDueEnrollments(): int;

    public function updateEnrollment(AutomationEnrollment $enrollment): void;

    /**
     * Step count and total delay_minutes per sequence, keyed by sequence ID.
     * @return array<int, array{count: int, total_minutes: int}>
     */
    public function getStepSummaries(): array;

    /**
     * Counts grouped by status for a given sequence.
     * @return array{active: int, completed: int, cancelled: int}
     */
    public function getEnrollmentCounts(int $sequenceId): array;

    /** Total active enrollments across all sequences (for nav badge etc.). */
    public function countActiveEnrollments(): int;

    public function recordOpen(string $trackingToken): void;

    public function recordClick(string $trackingToken): void;

    /** @return array{open_rate: float, click_rate: float} */
    public function getSubscriberEngagementStats(int $subscriberId): array;
}
