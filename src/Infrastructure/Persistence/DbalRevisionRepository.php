<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Content\Entity\Revision;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;

final class DbalRevisionRepository implements RevisionRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function save(Revision $revision): Revision
    {
        $data = [
            'post_id'         => $revision->getPostId(),
            'author_id'       => $revision->getAuthorId(),
            'title'           => $revision->getTitle(),
            'body'            => $revision->getBody(),
            'excerpt'         => $revision->getExcerpt(),
            'revision_number' => $revision->getRevisionNumber(),
            'created_at'      => $revision->getCreatedAt()->format('Y-m-d H:i:s'),
        ];

        if ($revision->getId() !== null) {
            $this->db->update(VL_DB_PREFIX . 'revisions', $data, ['id' => $revision->getId()]);
        } else {
            $this->db->insert(VL_DB_PREFIX . 'revisions', $data);
            $revision->setId((int) $this->db->lastInsertId());
        }

        return $revision;
    }

    public function findByPostId(int $postId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'revisions' . ' WHERE post_id = ? ORDER BY revision_number DESC',
            [$postId],
            [ParameterType::INTEGER],
        );

        return array_map(fn (array $row) => Revision::fromRow($row), $rows);
    }

    public function findById(int $id): ?Revision
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'revisions' . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );

        return $row !== false ? Revision::fromRow($row) : null;
    }

    public function countByPostId(int $postId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'revisions' . ' WHERE post_id = ?',
            [$postId],
            [ParameterType::INTEGER],
        );
    }

    public function nextRevisionNumber(int $postId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COALESCE(MAX(revision_number), 0) + 1 FROM ' . VL_DB_PREFIX . 'revisions WHERE post_id = ?',
            [$postId],
            [ParameterType::INTEGER],
        );
    }

    public function deleteOldestByPostId(int $postId, int $keepCount): void
    {
        if ($keepCount < 1) {
            return;
        }

        $ids = $this->db->fetchFirstColumn(
            'SELECT id FROM ' . VL_DB_PREFIX . 'revisions '
            . 'WHERE post_id = ? '
            . 'ORDER BY revision_number DESC '
            . 'LIMIT ?',
            [$postId, $keepCount],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        if (empty($ids)) {
            $this->db->delete(VL_DB_PREFIX . 'revisions', ['post_id' => $postId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->executeStatement(
            'DELETE FROM ' . VL_DB_PREFIX . 'revisions '
            . 'WHERE post_id = ? AND id NOT IN (' . $placeholders . ')',
            array_merge([$postId], $ids),
            array_merge([ParameterType::INTEGER], array_fill(0, count($ids), ParameterType::INTEGER)),
        );
    }

    public function deleteByPostId(int $postId): void
    {
        $this->db->delete(VL_DB_PREFIX . 'revisions', ['post_id' => $postId]);
    }

    public function deleteById(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'revisions', ['id' => $id]);
    }

    public function pruneAll(int $keepCount, ?string $contentType = null): int
    {
        if ($keepCount < 1) {
            return 0;
        }

        $postIds = $this->findRevisionPostIds($contentType);

        $totalDeleted = 0;
        foreach ($postIds as $postId) {
            $before = $this->db->fetchOne(
                'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'revisions' . ' WHERE post_id = ?',
                [(int) $postId],
                [ParameterType::INTEGER],
            );

            $this->deleteOldestByPostId((int) $postId, $keepCount);

            $after = $this->db->fetchOne(
                'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'revisions' . ' WHERE post_id = ?',
                [(int) $postId],
                [ParameterType::INTEGER],
            );

            $totalDeleted += ((int) $before - (int) $after);
        }

        return $totalDeleted;
    }

    public function countPrunable(int $keepCount, ?string $contentType = null): int
    {
        if ($keepCount < 1) {
            return 0;
        }

        $sql    = 'SELECT r.post_id, COUNT(*) AS total FROM ' . VL_DB_PREFIX . 'revisions r';
        $params = [];
        $types  = [];
        if ($contentType !== null && $contentType !== '') {
            $sql .= ' INNER JOIN ' . VL_DB_PREFIX . 'posts p ON p.id = r.post_id WHERE p.content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }
        $sql .= ' GROUP BY r.post_id';

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        $prunable = 0;
        foreach ($rows as $row) {
            $prunable += max(0, (int) $row['total'] - $keepCount);
        }

        return $prunable;
    }

    /**
     * @return list<int|string>
     */
    private function findRevisionPostIds(?string $contentType): array
    {
        if ($contentType === null || $contentType === '') {
            return $this->db->fetchFirstColumn(
                'SELECT DISTINCT post_id FROM ' . VL_DB_PREFIX . 'revisions',
            );
        }

        return $this->db->fetchFirstColumn(
            'SELECT DISTINCT r.post_id FROM ' . VL_DB_PREFIX . 'revisions r'
            . ' INNER JOIN ' . VL_DB_PREFIX . 'posts p ON p.id = r.post_id'
            . ' WHERE p.content_type = ?',
            [$contentType],
            [ParameterType::STRING],
        );
    }

    public function countAll(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'revisions',
        );
    }

    public function findPreviousByPostId(int $postId, int $revisionNumber): ?Revision
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'revisions' . ' WHERE post_id = ? AND revision_number < ? ORDER BY revision_number DESC LIMIT 1',
            [$postId, $revisionNumber],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return $row !== false ? Revision::fromRow($row) : null;
    }
}
