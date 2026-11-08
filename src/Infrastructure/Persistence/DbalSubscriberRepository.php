<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;

final class DbalSubscriberRepository implements SubscriberRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findById(int $id): ?Subscriber
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'newsletter_subscribers' . ' WHERE id = ?', [$id], [ParameterType::INTEGER]);
        if ($row === false) {
            return null;
        }
        return Subscriber::fromRow($row, $this->fetchTags((int) $row['id']));
    }

    public function findByEmail(string $email): ?Subscriber
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'newsletter_subscribers' . ' WHERE email = ?', [$email], [ParameterType::STRING]);
        if ($row === false) {
            return null;
        }
        return Subscriber::fromRow($row, $this->fetchTags((int) $row['id']));
    }

    public function findByToken(string $token): ?Subscriber
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'newsletter_subscribers' . ' WHERE token = ?', [$token], [ParameterType::STRING]);
        if ($row === false) {
            return null;
        }
        return Subscriber::fromRow($row, $this->fetchTags((int) $row['id']));
    }

    public function findByUserId(int $userId): ?Subscriber
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'newsletter_subscribers' . ' WHERE user_id = ?', [$userId], [ParameterType::INTEGER]);
        if ($row === false) {
            return null;
        }
        return Subscriber::fromRow($row, $this->fetchTags((int) $row['id']));
    }

    /** @return Subscriber[] */
    public function findAll(Pagination $pagination, array $filters = []): array
    {
        [$where, $params, $types] = $this->buildWhere($filters, 's');
        $orderBy                  = $this->buildOrderBy($pagination);
        $sql                      = 'SELECT s.*, us.expires_at AS subscription_expires_at, mp.name AS subscription_plan_name, us.status AS subscription_status FROM ' . VL_DB_PREFIX . 'newsletter_subscribers' . ' s'
            . ' LEFT JOIN ' . VL_DB_PREFIX . 'user_subscriptions' . ' us ON us.user_id = s.user_id AND us.status IN (?, ?, ?)'
            . ' LEFT JOIN ' . VL_DB_PREFIX . 'membership_plans' . ' mp ON mp.id = us.plan_id'
            . $where . $orderBy . ' LIMIT ? OFFSET ?';
        array_unshift($params, 'active', 'cancelling', 'cancelled');
        array_unshift($types, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING);
        $params[] = $pagination->perPage;
        $params[] = $pagination->offset;
        $types[]  = ParameterType::INTEGER;
        $types[]  = ParameterType::INTEGER;
        $rows     = $this->db->fetchAllAssociative($sql, $params, $types);
        return array_map(fn (array $row) => Subscriber::fromRow($row, $this->fetchTags((int) $row['id'])), $rows);
    }

    public function count(array $filters = []): int
    {
        [$where, $params, $types] = $this->buildWhere($filters, 's');
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'newsletter_subscribers' . ' s' . $where,
            $params,
            $types,
        );
    }

    /** @return Subscriber[] */
    public function findActive(
        ?string $tag = null,
        ?int $sourceFormId = null,
        ?int $minOrders = null,
        ?int $maxOrders = null,
        ?int $minOpenRate = null,
        ?int $maxOpenRate = null,
        ?int $minClickRate = null,
        ?int $maxClickRate = null
    ): array {
        [$join, $wheres, $params, $types] = $this->buildActiveWhere($tag, $sourceFormId, $minOrders, $maxOrders, $minOpenRate, $maxOpenRate, $minClickRate, $maxClickRate);
        $sql                              = 'SELECT s.* FROM ' . VL_DB_PREFIX . 'newsletter_subscribers' . ' s'
            . $join . ' WHERE ' . implode(' AND ', $wheres);
        // Deterministic ID order: queue resume logic skips by last processed subscriber ID.
        $sql .= ' ORDER BY s.id ASC';
        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        return array_map(fn (array $row) => Subscriber::fromRow($row, $this->fetchTags((int) $row['id'])), $rows);
    }

    public function countActive(
        ?string $tag = null,
        ?int $sourceFormId = null,
        ?int $minOrders = null,
        ?int $maxOrders = null,
        ?int $minOpenRate = null,
        ?int $maxOpenRate = null,
        ?int $minClickRate = null,
        ?int $maxClickRate = null
    ): int {
        [$join, $wheres, $params, $types] = $this->buildActiveWhere($tag, $sourceFormId, $minOrders, $maxOrders, $minOpenRate, $maxOpenRate, $minClickRate, $maxClickRate);
        $sql                              = 'SELECT COUNT(DISTINCT s.id) FROM ' . VL_DB_PREFIX . 'newsletter_subscribers' . ' s'
            . $join . ' WHERE ' . implode(' AND ', $wheres);
        return (int) $this->db->fetchOne($sql, $params, $types);
    }

    /**
     * Shared WHERE builder for the active-subscriber audience queries.
     *
     * @return array{string, string[], array, array}
     */
    private function buildActiveWhere(
        ?string $tag,
        ?int $sourceFormId,
        ?int $minOrders,
        ?int $maxOrders,
        ?int $minOpenRate,
        ?int $maxOpenRate,
        ?int $minClickRate,
        ?int $maxClickRate
    ): array {
        $join   = '';
        $wheres = ['s.status = ?'];
        $params = ['active'];
        $types  = [ParameterType::STRING];

        if ($tag !== null) {
            $join .= ' JOIN ' . VL_DB_PREFIX . 'newsletter_subscriber_tags' . ' t ON t.subscriber_id = s.id';
            $wheres[] = 't.tag = ?';
            $params[] = $tag;
            $types[]  = ParameterType::STRING;
        }
        if ($sourceFormId !== null) {
            $wheres[] = 's.source_form_id = ?';
            $params[] = $sourceFormId;
            $types[]  = ParameterType::INTEGER;
        }
        if ($minOrders !== null) {
            $wheres[] = 'COALESCE((SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'orders' . ' WHERE user_id = s.user_id AND status = ?), 0) >= ?';
            $params[] = 'completed';
            $params[] = $minOrders;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::INTEGER;
        }
        if ($maxOrders !== null) {
            $wheres[] = 'COALESCE((SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'orders' . ' WHERE user_id = s.user_id AND status = ?), 0) <= ?';
            $params[] = 'completed';
            $params[] = $maxOrders;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::INTEGER;
        }

        if ($minOpenRate !== null) {
            $wheres[] = '(SELECT CASE WHEN COUNT(r1.id) = 0 THEN 0 ELSE (COUNT(r1.opened_at) * 100.0 / COUNT(r1.id)) END '
                . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients r1 WHERE r1.subscriber_id = s.id) >= ?';
            $params[] = $minOpenRate;
            $types[]  = ParameterType::INTEGER;
        }

        if ($maxOpenRate !== null) {
            $wheres[] = '(SELECT CASE WHEN COUNT(r1b.id) = 0 THEN 0 ELSE (COUNT(r1b.opened_at) * 100.0 / COUNT(r1b.id)) END '
                . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients r1b WHERE r1b.subscriber_id = s.id) <= ?';
            $params[] = $maxOpenRate;
            $types[]  = ParameterType::INTEGER;
        }

        if ($minClickRate !== null) {
            $wheres[] = '(SELECT CASE WHEN COUNT(r2.id) = 0 THEN 0 ELSE (SUM(CASE WHEN r2.clicks_count > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(r2.id)) END '
                . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients r2 WHERE r2.subscriber_id = s.id) >= ?';
            $params[] = $minClickRate;
            $types[]  = ParameterType::INTEGER;
        }

        if ($maxClickRate !== null) {
            $wheres[] = '(SELECT CASE WHEN COUNT(r2b.id) = 0 THEN 0 ELSE (SUM(CASE WHEN r2b.clicks_count > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(r2b.id)) END '
                . ' FROM ' . VL_DB_PREFIX . 'newsletter_campaign_recipients r2b WHERE r2b.subscriber_id = s.id) <= ?';
            $params[] = $maxClickRate;
            $types[]  = ParameterType::INTEGER;
        }

        return [$join, $wheres, $params, $types];
    }

    public function save(Subscriber $subscriber): Subscriber
    {
        $data = [
            'email'           => $subscriber->getEmail(),
            'name'            => $subscriber->getName(),
            'status'          => $subscriber->getStatus(),
            'token'           => $subscriber->getToken(),
            'ip_address'      => $subscriber->getIpAddress(),
            'source_form_id'  => $subscriber->getSourceFormId(),
            'user_id'         => $subscriber->getUserId(),
            'tier'            => $subscriber->getTier(),
            'confirmed_at'    => $subscriber->getConfirmedAt()?->format('Y-m-d H:i:s'),
        ];

        if ($subscriber->getId() === null) {
            $data['subscribed_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $data['created_at']    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->insert(VL_DB_PREFIX . 'newsletter_subscribers', $data);
            $subscriber->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'newsletter_subscribers', $data, ['id' => $subscriber->getId()]);
        }

        // Sync tags
        $id = $subscriber->getId();
        $this->db->delete(VL_DB_PREFIX . 'newsletter_subscriber_tags', ['subscriber_id' => $id]);
        foreach ($subscriber->getTags() as $tag) {
            $this->db->insert(VL_DB_PREFIX . 'newsletter_subscriber_tags', ['subscriber_id' => $id, 'tag' => $tag]);
        }

        return $subscriber;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'newsletter_subscribers', ['id' => $id]);
    }

    /** @return string[] All unique tag names across all subscribers */
    public function getAllTags(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT DISTINCT tag FROM ' . VL_DB_PREFIX . 'newsletter_subscriber_tags' . ' ORDER BY tag ASC'
        );
        return array_column($rows, 'tag');
    }

    /** @return string[] */

    private function fetchTags(int $subscriberId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT tag FROM ' . VL_DB_PREFIX . 'newsletter_subscriber_tags' . ' WHERE subscriber_id = ?',
            [$subscriberId],
            [ParameterType::INTEGER],
        );
        return array_column($rows, 'tag');
    }

    /** @return array{0: string, 1: array<mixed>, 2: array<mixed>} */
    private function buildWhere(array $filters, string $alias = ''): array
    {
        $conditions = [];
        $params     = [];
        $types      = [];
        $p          = $alias !== '' ? $alias . '.' : '';

        if (!empty($filters['status'])) {
            $conditions[] = $p . 'status = ?';
            $params[]     = $filters['status'];
            $types[]      = ParameterType::STRING;
        }
        if (!empty($filters['search'])) {
            $conditions[] = '(LOWER(' . $p . 'email) LIKE LOWER(?) OR LOWER(' . $p . 'name) LIKE LOWER(?)'
                . ' OR EXISTS (SELECT 1 FROM ' . VL_DB_PREFIX . 'newsletter_subscriber_tags'
                . ' st WHERE st.subscriber_id = ' . $p . 'id AND LOWER(st.tag) LIKE LOWER(?)))';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $params, $types];
    }

    /**
     * Sorting by tags resolves to the alphabetically first tag of each
     * subscriber — the tags table holds many rows per subscriber, so it
     * cannot be part of the plain column whitelist.
     */
    private function buildOrderBy(Pagination $pagination): string
    {
        if ($pagination->sortBy === 'tags') {
            $dir = strtoupper($pagination->sortDir) === 'ASC' ? 'ASC' : 'DESC';
            return ' ORDER BY COALESCE((SELECT MIN(tg.tag) FROM ' . VL_DB_PREFIX . 'newsletter_subscriber_tags'
                . ' tg WHERE tg.subscriber_id = s.id), \'\') ' . $dir;
        }
        return $pagination->orderByClause(
            ['id', 'email', 'status', 'created_at'],
            'id DESC',
            's',
        );
    }
}
