<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Content\Entity\Tag;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class DbalTagRepository implements TagRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function findById(int $id): ?Tag
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'tags' . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );

        return $row !== false ? Tag::fromRow($row) : null;
    }

    public function findByName(string $name, ?string $contentType = null): ?Tag
    {
        $sql    = 'SELECT * FROM ' . VL_DB_PREFIX . 'tags' . ' WHERE name = ?';
        $params = [$name];
        $types  = [ParameterType::STRING];

        if ($contentType !== null) {
            $sql    .= ' AND content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }

        $row = $this->db->fetchAssociative($sql, $params, $types);

        return $row !== false ? Tag::fromRow($row) : null;
    }

    /** @inheritDoc */
    public function findAll(?Pagination $pagination = null, ?string $contentType = null, ?string $language = null, bool $fallback = false): array
    {
        $table  = VL_DB_PREFIX . 'tags';
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

        if ($pagination !== null) {
            $orderBy = $pagination->orderByClause(
                ['id', 'name', 'slug', 'created_at'],
                'name ASC',
            );
            $sql   .= $orderBy . ' LIMIT ? OFFSET ?';
            $params[] = $pagination->perPage;
            $params[] = $pagination->offset;
            $types[]  = ParameterType::INTEGER;
            $types[]  = ParameterType::INTEGER;

            $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        } else {
            $sql .= ' ORDER BY name ASC';
            $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        }

        return array_map(fn (array $row) => Tag::fromRow($row), $rows);
    }

    /** @inheritDoc */
    public function findAllWithUsageCount(
        ?Pagination $pagination = null,
        ?string $contentType = null,
        ?string $language = null,
        bool $fallback = false,
        ?string $search = null,
    ): array {
        $table  = VL_DB_PREFIX . 'tags';
        $sql    = 'SELECT t.*, COUNT(pt.post_id) AS usage_count'
            . ' FROM ' . $table . ' t'
            . ' LEFT JOIN ' . VL_DB_PREFIX . 'post_tags' . ' pt ON pt.tag_id = t.id';
        $params = [];
        $types  = [];
        $where  = [];

        if ($contentType !== null) {
            $where[]  = 't.content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }

        if ($language !== null) {
            if ($fallback) {
                $where[]  = '(t.language = ? OR (t.translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' t2
                    WHERE t2.translation_of = t.id AND t2.language = ?
                )))';
                $params[] = $language;
                $params[] = $language;
                $types[]  = ParameterType::STRING;
                $types[]  = ParameterType::STRING;
            } else {
                $where[]  = 't.language = ?';
                $params[] = $language;
                $types[]  = ParameterType::STRING;
            }
        }

        if ($search !== null && trim($search) !== '') {
            $where[]  = '(LOWER(t.name) LIKE LOWER(?) OR LOWER(t.slug) LIKE LOWER(?))';
            $term     = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' GROUP BY t.id';

        if ($pagination !== null) {
            $orderBy = $pagination->orderByClause(
                ['id', 'name', 'slug', 'created_at', 'usage_count', 'content_type'],
                'usage_count DESC, name ASC',
            );
            $sql   .= $orderBy . ' LIMIT ? OFFSET ?';
            $params[] = $pagination->perPage;
            $params[] = $pagination->offset;
            $types[]  = ParameterType::INTEGER;
            $types[]  = ParameterType::INTEGER;

            $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        } else {
            $sql .= ' ORDER BY usage_count DESC, name ASC';
            $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        }

        return array_map(fn (array $row) => [
            'tag'        => Tag::fromRow($row),
            'usageCount' => (int) $row['usage_count'],
        ], $rows);
    }

    public function count(
        ?string $contentType = null,
        ?string $language = null,
        bool $fallback = false,
        ?string $search = null,
    ): int {
        $table  = VL_DB_PREFIX . 'tags';
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

        if ($search !== null && trim($search) !== '') {
            $where[]  = '(LOWER(name) LIKE LOWER(?) OR LOWER(slug) LIKE LOWER(?))';
            $term     = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return (int) $this->db->fetchOne($sql, $params, $types);
    }

    public function save(Tag $tag): Tag
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'name'           => $tag->getName(),
            'language'       => $tag->getLanguage(),
            'translation_of' => $tag->getTranslationOf(),
            'slug'           => (string) $tag->getSlug(),
            'content_type'   => $tag->getContentType(),
        ];

        if ($tag->isNew()) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'tags', $data);
            $tag->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'tags', $data, ['id' => $tag->getId()]);
        }

        return $tag;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'tags', ['id' => $id]);
    }

    /** @inheritDoc */
    public function findOrCreateByNames(array $names, string $contentType = 'post', ?string $language = null): array
    {
        $tags = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $existing = $this->findByName($name, $contentType);
            if ($existing !== null) {
                $tags[] = $existing;
                continue;
            }

            $tag = new Tag($name, Slug::fromString($name), $contentType);
            if ($language !== null) {
                $tag->setLanguage($language);
            }
            $this->save($tag);
            $tags[] = $tag;
        }

        return $tags;
    }

    public function findBySlug(string $slug, ?string $contentType = null, ?string $language = null): ?Tag
    {
        $sql    = 'SELECT * FROM ' . VL_DB_PREFIX . 'tags' . ' WHERE slug = ?';
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

        return $row !== false ? Tag::fromRow($row) : null;
    }

    /** @inheritDoc */
    public function findPostsByTagId(int $tagId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.id, p.title, p.content_type, p.status, p.created_at'
            . ' FROM ' . VL_DB_PREFIX . 'posts' . ' p'
            . ' JOIN ' . VL_DB_PREFIX . 'post_tags' . ' pt ON pt.post_id = p.id'
            . ' WHERE pt.tag_id = ?'
            . ' ORDER BY p.created_at DESC',
            [$tagId],
            [ParameterType::INTEGER],
        );

        return array_map(fn (array $row) => [
            'id'          => (int) $row['id'],
            'title'       => $row['title'],
            'contentType' => $row['content_type'] ?? 'post',
            'status'      => $row['status']       ?? 'draft',
            'createdAt'   => $row['created_at']   ?? '',
        ], $rows);
    }

    /** @inheritDoc */
    public function findPotentialOriginals(
        ?string $contentType = null,
        ?int $excludeId = null,
        ?string $language = null,
    ): array {
        $p      = VL_DB_PREFIX;
        $select = 'SELECT t.*';
        $params = [];
        $types  = [];

        if ($language !== null) {
            $select .= ', CASE WHEN (t.language = ? OR EXISTS (SELECT 1 FROM ' . $p . 'tags sub'
                . ' WHERE sub.translation_of = t.id AND sub.language = ?'
                . ($excludeId !== null ? ' AND sub.id != ?' : '')
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

        $sql = $select . ' FROM ' . $p . 'tags t WHERE t.translation_of IS NULL';

        if ($contentType !== null) {
            $sql     .= ' AND t.content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }

        if ($excludeId !== null) {
            $sql     .= ' AND t.id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        $sql .= ' ORDER BY t.name ASC LIMIT 500';
        $rows        = $this->db->fetchAllAssociative($sql, $params, $types);
        $originals   = array_map(fn (array $row) => Tag::fromRow($row), $rows);
        $originalIds = array_map(fn (array $r) => (int) $r['id'], $rows);
        $this->hydrateTranslatedLanguages($originals, $originalIds);
        return $originals;
    }

    /** @inheritDoc */
    public function findTranslations(int $originalId): array
    {
        $sql  = 'SELECT * FROM ' . VL_DB_PREFIX . 'tags' . ' WHERE id = ? OR translation_of = ? ORDER BY language ASC';
        $rows = $this->db->fetchAllAssociative($sql, [$originalId, $originalId], [ParameterType::INTEGER, ParameterType::INTEGER]);
        return array_map(fn (array $row) => Tag::fromRow($row), $rows);
    }

    /** @inheritDoc */
    public function findTranslationsForRoots(array $rootIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $rootIds)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'tags'
            . ' WHERE id IN (?) OR translation_of IN (?) ORDER BY language ASC',
            [$ids, $ids],
            [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER],
        );

        $grouped = [];
        foreach ($rows as $row) {
            $root             = (int) ($row['translation_of'] ?? $row['id']);
            $grouped[$root][] = Tag::fromRow($row);
        }

        return $grouped;
    }

    /** @inheritDoc */
    public function findTranslatedNames(array $names, string $language): array
    {
        $names = array_values(array_filter(array_map('trim', $names), fn (string $n) => $n !== ''));

        if (empty($names)) {
            return [];
        }

        $p = VL_DB_PREFIX . 'tags';

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, name, COALESCE(translation_of, id) AS root_id FROM {$p} WHERE name IN (?)",
            [$names],
            [ArrayParameterType::STRING],
        );

        if (empty($rows)) {
            return $names;
        }

        $rootIds = array_unique(array_map(fn (array $r) => (int) $r['root_id'], $rows));

        $translations = $this->db->fetchAllAssociative(
            "SELECT id, name, translation_of FROM {$p} WHERE translation_of IN (?) AND language = ?",
            [$rootIds, $language],
            [ArrayParameterType::INTEGER, ParameterType::STRING],
        );

        // Build root_id -> translated name lookup
        $transMap = [];
        foreach ($translations as $t) {
            $transMap[(int) $t['translation_of']] = $t['name'];
        }

        // Build original name -> translated name map
        $map = [];
        foreach ($rows as $row) {
            $rootId             = (int) $row['root_id'];
            $originalName       = $row['name'];
            $map[$originalName] = $transMap[$rootId] ?? $originalName;
        }

        $result = [];
        foreach ($names as $name) {
            $result[] = $map[$name] ?? $name;
        }

        return $result;
    }

    /** @inheritDoc */
    /** @return array<int, int> */
    public function getUsageCounts(?string $contentType = null): array
    {
        $sql    = 'SELECT pt.tag_id, COUNT(*) AS cnt'
            . ' FROM ' . VL_DB_PREFIX . 'post_tags' . ' pt'
            . ' INNER JOIN ' . VL_DB_PREFIX . 'posts' . ' p ON p.id = pt.post_id'
            . ' WHERE p.status = ?';
        $params = ['published'];
        $types  = [ParameterType::STRING];

        if ($contentType !== null) {
            $sql    .= ' AND p.content_type = ?';
            $params[] = $contentType;
            $types[]  = ParameterType::STRING;
        }

        $sql .= ' GROUP BY pt.tag_id';

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        $map  = [];
        foreach ($rows as $row) {
            $map[(int) $row['tag_id']] = (int) $row['cnt'];
        }
        return $map;
    }

    /**
     * Bulk-load the languages each original already has a translation in.
     *
     * @param Tag[] $originals
     * @param int[] $originalIds
     */
    private function hydrateTranslatedLanguages(array $originals, array $originalIds): void
    {
        if (empty($originalIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($originalIds), '?'));
        $types        = array_fill(0, count($originalIds), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT translation_of, language FROM ' . VL_DB_PREFIX . 'tags'
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
