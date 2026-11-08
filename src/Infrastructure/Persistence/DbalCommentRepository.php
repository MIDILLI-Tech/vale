<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Comment\Entity\Comment;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;

final class DbalCommentRepository implements CommentRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findById(int $id): ?Comment
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE id = ?', [$id], [ParameterType::INTEGER]);
        return $row !== false ? Comment::fromRow($row) : null;
    }

    /** @return Comment[] */
    public function findByEntity(string $entityType, int $entityId, string $status = 'approved'): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE entity_type = ? AND entity_id = ? AND status = ? ORDER BY created_at ASC',
            [$entityType, $entityId, $status],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );
        return array_map(fn (array $row) => Comment::fromRow($row), $rows);
    }

    /** @return Comment[] */
    public function findByEntityPaginated(string $entityType, int $entityId, Pagination $pagination, string $status = 'approved'): array
    {
        $sql  = 'SELECT * FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE entity_type = ? AND entity_id = ? AND status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $rows = $this->db->fetchAllAssociative($sql, [
            $entityType, $entityId, $status, $pagination->perPage, $pagination->offset,
        ], [
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::INTEGER,
        ]);
        return array_map(fn (array $row) => Comment::fromRow($row), $rows);
    }

    public function findTopLevelByEntityPaginated(string $entityType, int $entityId, Pagination $pagination, string $status = 'approved'): array
    {
        $sql  = 'SELECT * FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE entity_type = ? AND entity_id = ? AND status = ? AND parent_id IS NULL ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $rows = $this->db->fetchAllAssociative($sql, [
            $entityType, $entityId, $status, $pagination->perPage, $pagination->offset,
        ], [
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::INTEGER,
        ]);
        return array_map(fn (array $row) => Comment::fromRow($row), $rows);
    }

    public function findAllChildrenByEntity(string $entityType, int $entityId, string $status = 'approved'): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE entity_type = ? AND entity_id = ? AND status = ? AND parent_id IS NOT NULL ORDER BY created_at ASC',
            [$entityType, $entityId, $status],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );
        return array_map(fn (array $row) => Comment::fromRow($row), $rows);
    }

    public function countByEntity(string $entityType, int $entityId, string $status = 'approved'): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE entity_type = ? AND entity_id = ? AND status = ?',
            [$entityType, $entityId, $status],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    public function countTopLevelByEntity(string $entityType, int $entityId, string $status = 'approved'): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE entity_type = ? AND entity_id = ? AND status = ? AND parent_id IS NULL',
            [$entityType, $entityId, $status],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    /** @return Comment[] */
    public function findAll(Pagination $pagination, array $filters = []): array
    {
        [$where, $params, $types] = $this->buildWhere($filters);
        $orderBy                  = $this->buildOrderBy($pagination);
        $sql                      = 'SELECT * FROM ' . VL_DB_PREFIX . 'comments' . $where . $orderBy . ' LIMIT ? OFFSET ?';
        $params[]                 = $pagination->perPage;
        $params[]                 = $pagination->offset;
        $types[]                  = ParameterType::INTEGER;
        $types[]                  = ParameterType::INTEGER;
        $rows                     = $this->db->fetchAllAssociative($sql, $params, $types);
        return array_map(fn (array $row) => Comment::fromRow($row), $rows);
    }

    public function count(array $filters = []): int
    {
        [$where, $params, $types] = $this->buildWhere($filters);
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'comments' . $where, $params, $types);
    }

    public function save(Comment $comment): Comment
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'entity_type'    => $comment->getEntityType(),
            'entity_id'      => $comment->getEntityId(),
            'parent_id'      => $comment->getParentId(),
            'author_name'    => $comment->getAuthorName(),
            'author_email'   => $comment->getAuthorEmail(),
            'author_url'     => $comment->getAuthorUrl(),
            'author_ip'      => $comment->getAuthorIp(),
            'user_id'        => $comment->getUserId(),
            'body'           => $comment->getBody(),
            'status'         => $comment->getStatus(),
            'verified'       => (int) $comment->isVerified(),
            'notify_replies' => (int) $comment->isNotifyReplies(),
            'locale'         => $comment->getLocale(),
        ];

        if ($comment->getId() === null) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'comments', $data);
            $comment->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'comments', $data, ['id' => $comment->getId()]);
        }

        return $comment;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'comments', ['id' => $id]);
    }

    public function hasChildren(int $parentId): bool
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE parent_id = ?',
            [$parentId]
        ) > 0;
    }

    public function calculateDepth(int $commentId): int
    {
        $depth     = 0;
        $currentId = $commentId;

        while ($currentId !== null) {
            $parentId = $this->db->fetchOne(
                'SELECT parent_id FROM ' . VL_DB_PREFIX . 'comments WHERE id = ?',
                [$currentId],
                [ParameterType::INTEGER],
            );

            if ($parentId === false || $parentId === null) {
                break;
            }

            $depth++;
            $currentId = (int) $parentId;
        }

        return $depth;
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE status = ?',
            [$status]
        );
    }

    public function deleteByStatus(string $status): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE status = ?',
            [$status]
        );
    }

    public function findByUserAndEntity(int $userId, string $entityType, int $entityId): ?Comment
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'comments' . ' WHERE user_id = ? AND entity_type = ? AND entity_id = ? ORDER BY created_at DESC LIMIT 1',
            [$userId, $entityType, $entityId],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER],
        );

        return $row !== false ? Comment::fromRow($row) : null;
    }

    /** @return array{0: string, 1: array<mixed>, 2: array<mixed>} */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params     = [];
        $types      = [];

        if (!empty($filters['status'])) {
            $conditions[] = 'status = ?';
            $params[]     = $filters['status'];
            $types[]      = ParameterType::STRING;
        }
        if (!empty($filters['entity_type'])) {
            $conditions[] = 'entity_type = ?';
            $params[]     = $filters['entity_type'];
            $types[]      = ParameterType::STRING;
        }
        if (!empty($filters['search'])) {
            $conditions[] = '(LOWER(author_name) LIKE LOWER(?) OR LOWER(author_email) LIKE LOWER(?) OR LOWER(body) LIKE LOWER(?)'
                . ' OR EXISTS (SELECT 1 FROM ' . VL_DB_PREFIX . 'posts' . ' po'
                . ' WHERE po.id = ' . VL_DB_PREFIX . 'comments' . '.entity_id'
                . ' AND ' . VL_DB_PREFIX . 'comments' . ".entity_type <> 'product'"
                . ' AND LOWER(po.title) LIKE LOWER(?))'
                . ' OR EXISTS (SELECT 1 FROM ' . VL_DB_PREFIX . 'products' . ' pr'
                . ' WHERE pr.id = ' . VL_DB_PREFIX . 'comments' . '.entity_id'
                . ' AND ' . VL_DB_PREFIX . 'comments' . ".entity_type = 'product'"
                . ' AND LOWER(pr.name) LIKE LOWER(?))'
                . ' OR EXISTS (SELECT 1 FROM ' . VL_DB_PREFIX . 'comments' . ' pc'
                . ' WHERE pc.id = ' . VL_DB_PREFIX . 'comments' . '.parent_id'
                . ' AND LOWER(pc.author_name) LIKE LOWER(?)))';
            $term     = '%' . trim((string) $filters['search']) . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $params, $types];
    }

    /**
     * Sorting by the "On" column resolves the entity title of each comment —
     * posts for content types, products for reviews. Mirrors the title the
     * admin listing renders, ties break by id DESC for stable pagination.
     */
    private function buildOrderBy(Pagination $pagination): string
    {
        if ($pagination->sortBy === 'entity_title') {
            $dir = strtoupper($pagination->sortDir) === 'ASC' ? 'ASC' : 'DESC';
            return ' ORDER BY COALESCE('
                . '(SELECT po.title FROM ' . VL_DB_PREFIX . 'posts' . ' po'
                . ' WHERE po.id = ' . VL_DB_PREFIX . 'comments' . '.entity_id'
                . ' AND ' . VL_DB_PREFIX . 'comments' . ".entity_type <> 'product'"
                . '), '
                . '(SELECT pr.name FROM ' . VL_DB_PREFIX . 'products' . ' pr'
                . ' WHERE pr.id = ' . VL_DB_PREFIX . 'comments' . '.entity_id'
                . ' AND ' . VL_DB_PREFIX . 'comments' . ".entity_type = 'product'"
                . '), \'\') ' . $dir . ', id DESC';
        }
        return $pagination->orderByClause(
            ['id', 'author_name', 'author_email', 'status', 'entity_type', 'created_at'],
            'id DESC',
        );
    }
}
