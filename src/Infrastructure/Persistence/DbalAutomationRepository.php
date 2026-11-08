<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Newsletter\Entity\AutomationEnrollment;
use ValeApp\Domain\Newsletter\Entity\AutomationSequence;
use ValeApp\Domain\Newsletter\Entity\AutomationStep;
use ValeApp\Domain\Newsletter\Repository\AutomationRepositoryInterface;

final class DbalAutomationRepository implements AutomationRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    // ── Sequences ─────────────────────────────────────────────────

    public function findAllSequences(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'automation_sequences' . ' ORDER BY created_at DESC'
        );
        return array_map(fn (array $r) => AutomationSequence::fromRow($r), $rows);
    }

    public function findSequenceById(int $id): ?AutomationSequence
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'automation_sequences' . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER]
        );
        return $row !== false ? AutomationSequence::fromRow($row) : null;
    }

    public function saveSequence(AutomationSequence $sequence): AutomationSequence
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'name'              => $sequence->getName(),
            'trigger_type'      => $sequence->getTriggerType(),
            'trigger_form_id'   => $sequence->getTriggerFormId(),
            'trigger_tag'       => $sequence->getTriggerTag(),
            'trigger_tag_match' => $sequence->getTriggerTagMatch(),
            'status'            => $sequence->getStatus(),
            'tracking_enabled'  => $sequence->getTrackingEnabled(),
            'updated_at'        => $now,
        ];

        if ($sequence->getId() === null) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'automation_sequences', $data);
            $sequence->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'automation_sequences', $data, ['id' => $sequence->getId()]);
        }

        return $sequence;
    }

    public function deleteSequence(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'automation_sequences', ['id' => $id]);
    }

    // ── Steps ─────────────────────────────────────────────────────

    public function findStepsBySequenceId(int $sequenceId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'automation_steps' . ' WHERE sequence_id = ? ORDER BY sort_order ASC',
            [$sequenceId],
            [ParameterType::INTEGER]
        );
        return array_map(fn (array $r) => AutomationStep::fromRow($r), $rows);
    }

    public function saveStep(AutomationStep $step): AutomationStep
    {
        $data = [
            'sequence_id'           => $step->getSequenceId(),
            'sort_order'            => $step->getSortOrder(),
            'delay_minutes'         => $step->getDelayMinutes(),
            'ab_test_enabled'       => $step->isAbTestEnabled() ? 1 : 0,
            'variation_b_weight'    => $step->getVariationBWeight(),
            'min_orders_filter'     => $step->getMinOrdersFilter(),
            'max_orders_filter'     => $step->getMaxOrdersFilter(),
            'min_open_rate_filter'  => $step->getMinOpenRateFilter(),
            'max_open_rate_filter'  => $step->getMaxOpenRateFilter(),
            'min_click_rate_filter' => $step->getMinClickRateFilter(),
            'max_click_rate_filter' => $step->getMaxClickRateFilter(),
            'subject'               => $step->getSubject(),
            'subject_b'             => $step->getSubjectB(),
            'body_html'             => $step->getBodyHtml(),
            'body_html_b'           => $step->getBodyHtmlB(),
            'opens_count'           => $step->getOpensCount(),
            'clicks_count'          => $step->getClicksCount(),
            'opens_count_b'         => $step->getOpensCountB(),
            'clicks_count_b'        => $step->getClicksCountB(),
        ];

        if ($step->getId() === null) {
            $data['created_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->insert(VL_DB_PREFIX . 'automation_steps', $data);
            $step->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'automation_steps', $data, ['id' => $step->getId()]);
        }

        return $step;
    }

    public function deleteStep(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'automation_steps', ['id' => $id]);
    }

    public function deleteStepsBySequenceId(int $sequenceId): void
    {
        $this->db->delete(VL_DB_PREFIX . 'automation_steps', ['sequence_id' => $sequenceId]);
    }

    // ── Enrollments ───────────────────────────────────────────────

    public function enroll(int $sequenceId, int $subscriberId, \DateTimeImmutable $nextSendAt): void
    {
        // Idempotent: skip if already enrolled in this sequence
        $existing = $this->db->fetchOne(
            'SELECT id FROM ' . VL_DB_PREFIX . 'automation_enrollments' . ' WHERE sequence_id = ? AND subscriber_id = ?',
            [$sequenceId, $subscriberId],
            [ParameterType::INTEGER, ParameterType::INTEGER]
        );
        if ($existing !== false) {
            return;
        }

        $this->db->insert(VL_DB_PREFIX . 'automation_enrollments', [
            'sequence_id'   => $sequenceId,
            'subscriber_id' => $subscriberId,
            'next_step'     => 0,
            'next_send_at'  => $nextSendAt->format('Y-m-d H:i:s'),
            'status'        => 'active',
            'enrolled_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function findDueEnrollments(int $limit): array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $rows = $this->db->fetchAllAssociative(
            'SELECT e.* FROM ' . VL_DB_PREFIX . 'automation_enrollments' . ' e'
            . ' WHERE e.status = ? AND e.next_send_at <= ?'
            . ' ORDER BY e.next_send_at ASC LIMIT ?',
            ['active', $now, $limit],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]
        );
        return array_map(fn (array $r) => AutomationEnrollment::fromRow($r), $rows);
    }

    public function countDueEnrollments(): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'automation_enrollments' . ' WHERE status = ? AND next_send_at <= ?',
            ['active', $now],
            [ParameterType::STRING, ParameterType::STRING]
        );
    }

    public function updateEnrollment(AutomationEnrollment $enrollment): void
    {
        $this->db->update(VL_DB_PREFIX . 'automation_enrollments', [
            'next_step'      => $enrollment->getNextStep(),
            'next_send_at'   => $enrollment->getNextSendAt()?->format('Y-m-d H:i:s'),
            'status'         => $enrollment->getStatus(),
            'last_variation' => $enrollment->getLastVariation(),
            'tracking_token' => $enrollment->getTrackingToken(),
            'completed_at'   => $enrollment->getCompletedAt()?->format('Y-m-d H:i:s'),
        ], ['id' => $enrollment->getId()]);
    }

    public function getStepSummaries(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT sequence_id, COUNT(*) AS step_count, COALESCE(SUM(delay_minutes), 0) AS total_minutes'
            . ' FROM ' . VL_DB_PREFIX . 'automation_steps' . ' GROUP BY sequence_id'
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['sequence_id']] = [
                'count'         => (int) $row['step_count'],
                'total_minutes' => (int) $row['total_minutes'],
            ];
        }
        return $result;
    }

    public function getEnrollmentCounts(int $sequenceId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT status, COUNT(*) AS cnt FROM ' . VL_DB_PREFIX . 'automation_enrollments'
            . ' WHERE sequence_id = ? GROUP BY status',
            [$sequenceId],
            [ParameterType::INTEGER]
        );
        $result = ['active' => 0, 'completed' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['cnt'];
        }
        return $result;
    }

    public function countActiveEnrollments(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'automation_enrollments' . ' WHERE status = ?',
            ['active'],
            [ParameterType::STRING]
        );
    }

    public function recordOpen(string $trackingToken): void
    {
        $recipient = $this->db->fetchAssociative(
            'SELECT sequence_id, next_step, last_variation FROM ' . VL_DB_PREFIX . 'automation_enrollments' . ' WHERE tracking_token = ?',
            [$trackingToken],
            [ParameterType::STRING]
        );

        if ($recipient === false) {
            return;
        }

        $variation = $recipient['last_variation'] ?? 'A';
        $field     = ($variation === 'B') ? 'opens_count_b' : 'opens_count';

        $this->db->executeStatement(
            "UPDATE " . VL_DB_PREFIX . "automation_steps SET {$field} = {$field} + 1 WHERE sequence_id = ? AND sort_order = ?",
            [$recipient['sequence_id'], $recipient['next_step'] - 1], // next_step was already advanced
            [ParameterType::INTEGER, ParameterType::INTEGER]
        );
    }

    public function recordClick(string $trackingToken): void
    {
        $recipient = $this->db->fetchAssociative(
            'SELECT sequence_id, next_step, last_variation FROM ' . VL_DB_PREFIX . 'automation_enrollments' . ' WHERE tracking_token = ?',
            [$trackingToken],
            [ParameterType::STRING]
        );

        if ($recipient === false) {
            return;
        }

        $variation = $recipient['last_variation'] ?? 'A';
        $field     = ($variation === 'B') ? 'clicks_count_b' : 'clicks_count';

        $this->db->executeStatement(
            "UPDATE " . VL_DB_PREFIX . "automation_steps SET {$field} = {$field} + 1 WHERE sequence_id = ? AND sort_order = ?",
            [$recipient['sequence_id'], $recipient['next_step'] - 1],
            [ParameterType::INTEGER, ParameterType::INTEGER]
        );
    }

    public function getSubscriberEngagementStats(int $subscriberId): array
    {
        $sql = 'SELECT '
            . ' COUNT(id) as sent_count, '
            . ' COUNT(opened_at) as open_count, '
            . ' SUM(clicks_count) as click_count '
            . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients '
            . ' WHERE subscriber_id = ?';

        $row = $this->db->fetchAssociative($sql, [$subscriberId], [ParameterType::INTEGER]);

        if ($row === false || (int)$row['sent_count'] === 0) {
            return ['open_rate' => 0.0, 'click_rate' => 0.0];
        }

        $sent = (int)$row['sent_count'];
        return [
            'open_rate'  => ((int)$row['open_count'] / $sent)  * 100.0,
            'click_rate' => ((int)$row['click_count'] / $sent) * 100.0,
        ];
    }
}
