<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Content\Entity\Category;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;

final class DbalCategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function findById(int $id): ?Category
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'categories' . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );

        return $row !== false ? Category::fromRow($row) : null;
    }

    /** @inheritDoc */
    public function findAll(?Pagination $pagination = null, ?string $contentType = null, ?string $language = null, bool $fallback = false): array
    {
        $table  = VL_DB_PREFIX . 'categories';
        $sql    = 'SELECT * FROM ' . $table;
        $params = [];
        $types  = [];
        $where  = [];

        if ($contentType !== null) {
            $where[]  = 'content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }

        if ($language !== null) {
            if ($fallback) {
                $where[]  = '(language = ? OR (translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' t2
                    WHERE t2.translation_of = ' . $table . '.id AND t2.language = ?
                )))';
                $params[] = $language;
                $params[] = $language;
                $types[]  = ParameterType::STRING;
                $types[]  = ParameterType::STRING;
            } else {
                $where[]  = 'language = ?';
                $params[] = $language;
                $types[]  = ParameterType::STRING;
            }
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY sort_order ASC, name ASC';

        if ($pagination !== null) {
            $sql   .= ' LIMIT ? OFFSET ?';
            $params[] = $pagination->perPage;
            $params[] = $pagination->offset;
            $types[]  = ParameterType::INTEGER;
            $types[]  = ParameterType::INTEGER;
        }

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        return array_map(fn (array $row) => Category::fromRow($row), $rows);
    }

    public function count(?string $contentType = null, ?string $language = null, bool $fallback = false): int
    {
        $table  = VL_DB_PREFIX . 'categories';
        $sql    = 'SELECT COUNT(*) FROM ' . $table;
        $params = [];
        $types  = [];
        $where  = [];

        if ($contentType !== null) {
            $where[]  = 'content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }

        if ($language !== null) {
            if ($fallback) {
                $where[]  = '(language = ? OR (translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' t2
                    WHERE t2.translation_of = ' . $table . '.id AND t2.language = ?
                )))';
                $params[] = $language;
                $params[] = $language;
                $types[]  = ParameterType::STRING;
                $types[]  = ParameterType::STRING;
            } else {
                $where[]  = 'language = ?';
                $params[] = $language;
                $types[]  = ParameterType::STRING;
            }
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return (int) $this->db->fetchOne($sql, $params, $types);
    }

    public function save(Category $category): Category
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'name'           => $category->getName(),
            'language'       => $category->getLanguage(),
            'translation_of' => $category->getTranslationOf(),
            'slug'           => (string) $category->getSlug(),
            'description'    => $category->getDescription(),
            'parent_id'      => $category->getParentId(),
            'sort_order'     => $category->getSortOrder(),
            'content_type'   => $category->getContentType(),
        ];

        if ($category->isNew()) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'categories', $data);
            $category->setId((int) $this->db->lastInsertId());
        } else {
            $data['updated_at'] = $now;
            $this->db->update(VL_DB_PREFIX . 'categories', $data, ['id' => $category->getId()]);
        }

        return $category;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'categories', ['id' => $id]);
    }

    public function slugExists(string $slug, ?int $excludeId = null, ?string $contentType = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'categories' . ' WHERE slug = ?';
        $params = [$slug];
        $types  = [ParameterType::STRING];

        if ($contentType !== null) {
            $sql    .= ' AND content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }

        if ($excludeId !== null) {
            $sql    .= ' AND id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        return (int) $this->db->fetchOne($sql, $params, $types) > 0;
    }

    public function findBySlug(string $slug, ?string $contentType = null, ?string $language = null): ?Category
    {
        $sql    = 'SELECT * FROM ' . VL_DB_PREFIX . 'categories' . ' WHERE slug = ?';
        $params = [$slug];
        $types  = [ParameterType::STRING];

        if ($contentType !== null) {
            $sql    .= ' AND content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }

        if ($language !== null) {
            $sql    .= ' AND language = ?';
            $params[] = $language;
            $types[]  = ParameterType::STRING;
        }

        $row = $this->db->fetchAssociative($sql, $params, $types);

        return $row !== false ? Category::fromRow($row) : null;
    }

    /** @return array<int, int> */
    public function getUsageCounts(?string $contentType = null): array
    {
        $sql    = 'SELECT pc.category_id, COUNT(*) AS cnt'
            . ' FROM ' . VL_DB_PREFIX . 'post_categories' . ' pc'
            . ' INNER JOIN ' . VL_DB_PREFIX . 'posts' . ' p ON p.id = pc.post_id'
            . ' WHERE p.status = ?';
        $params = ['published'];
        $types  = [ParameterType::STRING];

        if ($contentType !== null) {
            $sql    .= ' AND p.content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }

        $sql .= ' GROUP BY pc.category_id';

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        $map  = [];
        foreach ($rows as $row) {
            $map[(int) $row['category_id']] = (int) $row['cnt'];
        }
        return $map;
    }

    /** @return array<array{id:int, title:string, status:string, createdAt:string, contentType:string}> */
    public function findPostsByCategoryId(int $categoryId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.id, p.title, p.status, p.content_type, p.created_at'
            . ' FROM ' . VL_DB_PREFIX . 'posts' . ' p'
            . ' JOIN ' . VL_DB_PREFIX . 'post_categories' . ' pc ON pc.post_id = p.id'
            . ' WHERE pc.category_id = ?'
            . ' ORDER BY p.created_at DESC',
            [$categoryId],
            [ParameterType::INTEGER],
        );

        return array_map(fn (array $row) => [
            'id'          => (int) $row['id'],
            'title'       => $row['title'],
            'status'      => $row['status']        ?? 'draft',
            'contentType' => $row['content_type']  ?? 'post',
            'createdAt'   => $row['created_at']    ?? '',
        ], $rows);
    }

    /** @inheritDoc */
    public function findPotentialOriginals(
        ?string $contentType = null,
        ?int $excludeId = null,
        ?string $language = null,
    ): array {
        $p      = VL_DB_PREFIX;
        $select = 'SELECT c.*';
        $params = [];
        $types  = [];

        if ($language !== null) {
            $select .= ', CASE WHEN (c.language = ? OR EXISTS (SELECT 1 FROM ' . $p . 'categories t'
                . ' WHERE t.translation_of = c.id AND t.language = ?'
                . ($excludeId !== null ? ' AND t.id != ?' : '')
                . ')) THEN 1 ELSE 0 END AS is_translated_in_target';
            $params[] = $language;
            $params[] = $language;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
            if ($excludeId !== null) {
                $params[] = $excludeId;
                $types[]  = ParameterType::INTEGER;
            }
        }

        $sql = $select . ' FROM ' . $p . 'categories c WHERE c.translation_of IS NULL';

        if ($contentType !== null) {
            $sql     .= ' AND c.content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }

        if ($excludeId !== null) {
            $sql     .= ' AND c.id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        $sql .= ' ORDER BY c.name ASC LIMIT 500';
        $rows        = $this->db->fetchAllAssociative($sql, $params, $types);
        $originals   = array_map(fn (array $row) => Category::fromRow($row), $rows);
        $originalIds = array_map(fn (array $r) => (int) $r['id'], $rows);
        $this->hydrateTranslatedLanguages($originals, $originalIds);
        return $originals;
    }

    /** @inheritDoc */
    public function findTranslations(int $originalId): array
    {
        $sql  = 'SELECT * FROM ' . VL_DB_PREFIX . 'categories' . ' WHERE id = ? OR translation_of = ? ORDER BY language ASC';
        $rows = $this->db->fetchAllAssociative($sql, [$originalId, $originalId], [ParameterType::INTEGER, ParameterType::INTEGER]);
        return array_map(fn (array $row) => Category::fromRow($row), $rows);
    }

    /** @inheritDoc */
    public function findTranslationsForRoots(array $rootIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $rootIds)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'categories'
            . ' WHERE id IN (?) OR translation_of IN (?) ORDER BY language ASC',
            [$ids, $ids],
            [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER],
        );

        $grouped = [];
        foreach ($rows as $row) {
            $root             = (int) ($row['translation_of'] ?? $row['id']);
            $grouped[$root][] = Category::fromRow($row);
        }

        return $grouped;
    }

    /** @inheritDoc */
    public function findTranslatedIds(array $ids, string $language): array
    {
        if (empty($ids)) {
            return [];
        }

        $ids = array_map('intval', $ids);

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, COALESCE(translation_of, id) AS root_id FROM ' . VL_DB_PREFIX . 'categories' . ' WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        $rootIds = array_unique(array_map(fn (array $r) => (int) $r['root_id'], $rows));

        if (empty($rootIds)) {
            return [];
        }

        $translated = $this->db->fetchAllAssociative(
            'SELECT id FROM ' . VL_DB_PREFIX . 'categories' . ' WHERE translation_of IN (?) AND language = ?',
            [$rootIds, $language],
            [ArrayParameterType::INTEGER, ParameterType::STRING],
        );

        return array_map(fn (array $r) => (int) $r['id'], $translated);
    }

    /**
     * Bulk-load the languages each original already has a translation in.
     *
     * @param Category[] $originals
     * @param int[]      $originalIds
     */
    private function hydrateTranslatedLanguages(array $originals, array $originalIds): void
    {
        if (empty($originalIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($originalIds), '?'));
        $types        = array_fill(0, count($originalIds), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT translation_of, language FROM ' . VL_DB_PREFIX . 'categories'
            . ' WHERE translation_of IN (' . $placeholders . ')',
            $originalIds,
            $types
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['translation_of']][] = (string) $r['language'];
        }
        foreach ($originals as $original) {
            $original->setTranslatedLanguages($map[(int) $original->getId()] ?? []);
        }
    }
}
