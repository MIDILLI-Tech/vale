<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Newsletter\Entity\Campaign;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;

final class DbalCampaignRepository implements CampaignRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findById(int $id): ?Campaign
    {
        $sql = 'SELECT c.*, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id) AS recipients_count, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id AND r.variation = \'A\') AS recipients_count_a, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id AND r.variation = \'B\') AS recipients_count_b '
            . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaigns' . ' c WHERE c.id = ?';
        $row = $this->db->fetchAssociative($sql, [$id], [ParameterType::INTEGER]);
        return $row !== false ? Campaign::fromRow($row) : null;
    }

    /** @return Campaign[] */
    public function findAll(Pagination $pagination, ?string $search = null, bool $searchBody = false): array
    {
        $orderBy = $pagination->orderByClause(
            ['id', 'subject', 'status', 'opens_count', 'clicks_count', 'sent_at', 'created_at'],
            'id DESC',
        );
        $where   = '';
        $params  = [];
        $types   = [];
        if ($search !== null && trim($search) !== '') {
            $term   = '%' . trim($search) . '%';
            $ors    = ['LOWER(c.subject) LIKE LOWER(?)', 'LOWER(c.subject_b) LIKE LOWER(?)', 'LOWER(c.from_email) LIKE LOWER(?)'];
            $params = [$term, $term, $term];
            $types  = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];
            if ($searchBody) {
                $ors[]    = 'LOWER(c.body_html) LIKE LOWER(?)';
                $ors[]    = 'LOWER(COALESCE(c.body_html_b, \'\')) LIKE LOWER(?)';
                $params[] = $term;
                $params[] = $term;
                $types[]  = ParameterType::STRING;
                $types[]  = ParameterType::STRING;
            }
            $where = ' WHERE (' . implode(' OR ', $ors) . ')';
        }
        $sql  = 'SELECT c.*, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id) AS recipients_count, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id AND r.variation = \'A\') AS recipients_count_a, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id AND r.variation = \'B\') AS recipients_count_b '
            . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaigns' . ' c' . $where . $orderBy . ' LIMIT ? OFFSET ?';
        $rows = $this->db->fetchAllAssociative(
            $sql,
            [...$params, $pagination->perPage, $pagination->offset],
            [...$types, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        return array_map(fn (array $row) => Campaign::fromRow($row), $rows);
    }

    public function count(?string $search = null, bool $searchBody = false): int
    {
        if ($search !== null && trim($search) !== '') {
            $term   = '%' . trim($search) . '%';
            $ors    = ['LOWER(subject) LIKE LOWER(?)', 'LOWER(subject_b) LIKE LOWER(?)', 'LOWER(from_email) LIKE LOWER(?)'];
            $params = [$term, $term, $term];
            $types  = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];
            if ($searchBody) {
                $ors[]    = 'LOWER(body_html) LIKE LOWER(?)';
                $ors[]    = 'LOWER(COALESCE(body_html_b, \'\')) LIKE LOWER(?)';
                $params[] = $term;
                $params[] = $term;
                $types[]  = ParameterType::STRING;
                $types[]  = ParameterType::STRING;
            }
            return (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaigns'
                . ' WHERE (' . implode(' OR ', $ors) . ')',
                $params,
                $types,
            );
        }
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaigns');
    }

    public function save(Campaign $campaign): Campaign
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'subject'                => $campaign->getSubject(),
            'subject_b'              => $campaign->getSubjectB(),
            'body_html'              => $campaign->getBodyHtml(),
            'body_html_b'            => $campaign->getBodyHtmlB(),
            'from_name'              => $campaign->getFromName(),
            'from_email'             => $campaign->getFromEmail(),
            'status'                 => $campaign->getStatus(),
            'ab_test_enabled'        => $campaign->isAbTestEnabled() ? 1 : 0,
            'variation_b_weight'     => $campaign->getVariationBWeight(),
            'tag_filter'             => $campaign->getTagFilter(),
            'source_form_filter'     => $campaign->getSourceFormFilter(),
            'min_orders_filter'      => $campaign->getMinOrdersFilter(),
            'max_orders_filter'      => $campaign->getMaxOrdersFilter(),
            'min_open_rate_filter'   => $campaign->getMinOpenRateFilter(),
            'max_open_rate_filter'   => $campaign->getMaxOpenRateFilter(),
            'min_click_rate_filter'  => $campaign->getMinClickRateFilter(),
            'max_click_rate_filter'  => $campaign->getMaxClickRateFilter(),
            'scheduled_at'           => $campaign->getScheduledAt()?->format('Y-m-d H:i:s'),
            'sent_at'                => $campaign->getSentAt()?->format('Y-m-d H:i:s'),
            'opens_count'            => $campaign->getOpensCount(),
            'opens_count_b'          => $campaign->getOpensCountB(),
            'clicks_count'           => $campaign->getClicksCount(),
            'clicks_count_b'         => $campaign->getClicksCountB(),
            'processed_count'        => $campaign->getProcessedCount(),
            'total_count'            => $campaign->getTotalCount(),
            'last_subscriber_id'     => $campaign->getLastSubscriberId(),
            'tracking_enabled'       => $campaign->getTrackingEnabled(),
            'is_recurring'           => $campaign->isRecurring() ? 1 : 0,
            'recurring_interval'     => $campaign->getRecurringInterval(),
            'recurring_day'          => $campaign->getRecurringDay(),
            'recurring_time'         => $campaign->getRecurringTime(),
            'recurring_post_count'   => $campaign->getRecurringPostCount(),
            'recurring_post_types'   => $campaign->getRecurringPostTypes(),
            'recurring_category_id'  => $campaign->getRecurringCategoryId(),
            'recurring_tag_id'       => $campaign->getRecurringTagId(),
            'recurring_category_ids' => $campaign->getRecurringCategoryIds(),
            'recurring_tag_ids'      => $campaign->getRecurringTagIds(),
            'recurring_language'     => $campaign->getRecurringLanguage(),
            'last_recurring_run_at'  => $campaign->getLastRecurringRunAt()?->format('Y-m-d H:i:s'),
            'updated_at'             => $now,
        ];

        if ($campaign->getId() === null) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'newsletter_campaigns', $data);
            $campaign->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'newsletter_campaigns', $data, ['id' => $campaign->getId()]);
        }

        return $campaign;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'newsletter_campaigns', ['id' => $id]);
    }

    public function clearRecipients(int $campaignId): void
    {
        $this->db->delete(VL_DB_PREFIX . 'newsletter_campaign_recipients', ['campaign_id' => $campaignId]);
    }

    public function addRecipient(int $campaignId, int $subscriberId, string $variation = 'A'): string
    {
        $existing = $this->db->fetchAssociative(
            'SELECT tracking_token FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' WHERE campaign_id = ? AND subscriber_id = ?',
            [$campaignId, $subscriberId],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
        if ($existing !== false) {
            return $existing['tracking_token'];
        }

        $token = bin2hex(random_bytes(32));
        $this->db->insert(VL_DB_PREFIX . 'newsletter_campaign_recipients', [
            'campaign_id'    => $campaignId,
            'subscriber_id'  => $subscriberId,
            'tracking_token' => $token,
            'variation'      => $variation,
            'sent_at'        => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    /** @return array[] */
    public function findRecipientsByCampaignId(int $campaignId, Pagination $pagination, ?string $search = null, ?string $filter = null): array
    {
        $orderBy = $pagination->orderByClause(
            ['email', 'name', 'sent_at', 'opened_at', 'clicks_count', 'variation'],
            'r.sent_at DESC',
        );
        $where  = 'r.campaign_id = ?';
        $params = [$campaignId];
        if ($search !== null && $search !== '') {
            $where   .= ' AND (LOWER(s.email) LIKE LOWER(?) OR LOWER(s.name) LIKE LOWER(?))';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($filter === 'opened') {
            $where .= ' AND r.opened_at IS NOT NULL';
        } elseif ($filter === 'clicked') {
            $where .= ' AND r.clicks_count > 0';
        }
        $sql = 'SELECT r.tracking_token, r.sent_at, r.opened_at, r.clicks_count, r.variation,'
            . ' s.id AS subscriber_id, s.email, s.name, s.status AS subscriber_status'
            . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r'
            . ' JOIN ' . VL_DB_PREFIX . 'newsletter_subscribers' . ' s ON s.id = r.subscriber_id'
            . ' WHERE ' . $where . $orderBy . ' LIMIT ? OFFSET ?';
        $params[] = $pagination->perPage;
        $params[] = $pagination->offset;
        return $this->db->fetchAllAssociative($sql, $params, [
            ParameterType::INTEGER,
            ...($search !== null && $search !== '' ? [ParameterType::STRING, ParameterType::STRING] : []),
            ParameterType::INTEGER,
            ParameterType::INTEGER,
        ]);
    }

    public function countRecipientsByCampaignId(int $campaignId, ?string $search = null, ?string $filter = null): int
    {
        $where  = 'r.campaign_id = ?';
        $params = [$campaignId];
        if ($search !== null && $search !== '') {
            $where   .= ' AND (LOWER(s.email) LIKE LOWER(?) OR LOWER(s.name) LIKE LOWER(?))';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($filter === 'opened') {
            $where .= ' AND r.opened_at IS NOT NULL';
        } elseif ($filter === 'clicked') {
            $where .= ' AND r.clicks_count > 0';
        }
        $sql = 'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r'
            . ' JOIN ' . VL_DB_PREFIX . 'newsletter_subscribers' . ' s ON s.id = r.subscriber_id'
            . ' WHERE ' . $where;
        return (int) $this->db->fetchOne($sql, $params, [
            ParameterType::INTEGER,
            ...($search !== null && $search !== '' ? [ParameterType::STRING, ParameterType::STRING] : []),
        ]);
    }

    public function recordOpen(string $trackingToken): void
    {
        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $recipient = $this->db->fetchAssociative(
            'SELECT campaign_id, variation FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' WHERE tracking_token = ?',
            [$trackingToken],
            [ParameterType::STRING]
        );

        if ($recipient === false) {
            return;
        }

        $updated = $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' SET opened_at = ? WHERE tracking_token = ? AND opened_at IS NULL',
            [$now, $trackingToken],
            [ParameterType::STRING, ParameterType::STRING],
        );

        if ($updated > 0) {
            $field = ($recipient['variation'] === 'B') ? 'opens_count_b' : 'opens_count';
            $this->db->executeStatement(
                "UPDATE " . VL_DB_PREFIX . "newsletter_campaigns SET {$field} = {$field} + 1 WHERE id = ?",
                [$recipient['campaign_id']],
                [ParameterType::INTEGER]
            );
        }
    }

    public function recordClick(string $trackingToken): void
    {
        $recipient = $this->db->fetchAssociative(
            'SELECT campaign_id, variation FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' WHERE tracking_token = ?',
            [$trackingToken],
            [ParameterType::STRING]
        );

        if ($recipient === false) {
            return;
        }

        $updated = $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' SET clicks_count = 1 WHERE tracking_token = ? AND clicks_count = 0',
            [$trackingToken],
            [ParameterType::STRING],
        );
        if ($updated > 0) {
            $field = ($recipient['variation'] === 'B') ? 'clicks_count_b' : 'clicks_count';
            $this->db->executeStatement(
                "UPDATE " . VL_DB_PREFIX . "newsletter_campaigns SET {$field} = {$field} + 1 WHERE id = ?",
                [$recipient['campaign_id']],
                [ParameterType::INTEGER]
            );
        }
    }

    /** @return array<int, array{campaigns_count: int, opens_count: int, clicks_count: int}> */
    public function getSubscribersStats(array $subscriberIds): array
    {
        if ($subscriberIds === []) {
            return [];
        }
        $ids          = array_map('intval', $subscriberIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql          = 'SELECT subscriber_id, COUNT(*) AS campaigns_count,'
            . ' COUNT(opened_at) AS opens_count, COALESCE(SUM(clicks_count), 0) AS clicks_count'
            . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients'
            . ' WHERE subscriber_id IN (' . $placeholders . ')'
            . ' GROUP BY subscriber_id';
        $rows   = $this->db->fetchAllAssociative($sql, $ids, array_fill(0, count($ids), ParameterType::INTEGER));
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['subscriber_id']] = [
                'campaigns_count' => (int) $row['campaigns_count'],
                'opens_count'     => (int) $row['opens_count'],
                'clicks_count'    => (int) $row['clicks_count'],
            ];
        }
        return $result;
    }

    /** @return Campaign[] */
    public function findBySubscriberId(int $subscriberId, Pagination $pagination, ?string $filter = null): array
    {
        $orderBy = $pagination->orderByClause(
            ['id', 'subject', 'status', 'sent_at', 'created_at'],
            'c.id DESC',
        );
        $where  = 'r.campaign_id = c.id AND r.subscriber_id = ?';
        $params = [$subscriberId];
        if ($filter === 'opened') {
            $where .= ' AND r.opened_at IS NOT NULL';
        } elseif ($filter === 'clicked') {
            $where .= ' AND r.clicks_count > 0';
        }
        $sql = 'SELECT c.*, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r2 WHERE r2.campaign_id = c.id) AS recipients_count, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r2 WHERE r2.campaign_id = c.id AND r2.variation = \'A\') AS recipients_count_a, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r2 WHERE r2.campaign_id = c.id AND r2.variation = \'B\') AS recipients_count_b '
            . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaigns' . ' c'
            . ' JOIN ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r ON ' . $where
            . $orderBy . ' LIMIT ? OFFSET ?';
        $params[] = $pagination->perPage;
        $params[] = $pagination->offset;
        $rows     = $this->db->fetchAllAssociative($sql, $params, [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER]);
        return array_map(fn (array $row) => Campaign::fromRow($row), $rows);
    }

    public function countBySubscriberId(int $subscriberId, ?string $filter = null): int
    {
        $where  = 'r.campaign_id = c.id AND r.subscriber_id = ?';
        $params = [$subscriberId];
        if ($filter === 'opened') {
            $where .= ' AND r.opened_at IS NOT NULL';
        } elseif ($filter === 'clicked') {
            $where .= ' AND r.clicks_count > 0';
        }
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaigns' . ' c'
            . ' JOIN ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r ON ' . $where,
            $params,
            [ParameterType::INTEGER],
        );
    }

    /** @return Campaign[] */
    public function findDueForSending(): array
    {
        $sql  = 'SELECT c.*, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id) AS recipients_count, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id AND r.variation = \'A\') AS recipients_count_a, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id AND r.variation = \'B\') AS recipients_count_b '
            . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaigns' . " c WHERE c.status IN ('queued', 'processing') OR (c.status = 'scheduled' AND c.scheduled_at <= ?)";
        $rows = $this->db->fetchAllAssociative($sql, [(new \DateTimeImmutable())->format('Y-m-d H:i:s')], [ParameterType::STRING]);
        return array_map(fn (array $row) => Campaign::fromRow($row), $rows);
    }

    /** @return Campaign[] */
    public function findActiveRecurring(): array
    {
        $sql = 'SELECT c.*, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id) AS recipients_count, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id AND r.variation = \'A\') AS recipients_count_a, '
            . '(SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients' . ' r WHERE r.campaign_id = c.id AND r.variation = \'B\') AS recipients_count_b '
            . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaigns' . " c WHERE c.is_recurring = TRUE";
        $rows = $this->db->fetchAllAssociative($sql);
        return array_map(fn (array $row) => Campaign::fromRow($row), $rows);
    }
}
