<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class DbalPostRepository implements PostRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function findById(int $id): ?Post
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'posts' . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );

        if ($row === false) {
            return null;
        }

        return Post::fromRow(
            $row,
            $this->fetchCategoryIds((int) $row['id']),
            $this->fetchTagIds((int) $row['id']),
            $this->fetchMeta((int) $row['id']),
        );
    }

    public function findBySlug(Slug $slug, string $contentType = 'post', ?string $language = null): ?Post
    {
        $sql    = 'SELECT * FROM ' . VL_DB_PREFIX . 'posts' . ' WHERE slug = ? AND content_type = ?';
        $params = [(string) $slug, $contentType];
        $types  = [ParameterType::STRING, ParameterType::STRING];

        if ($language !== null) {
            $sql .= ' AND language = ?';
            $params[] = $language;
            $types[]  = ParameterType::STRING;
        }

        $row = $this->db->fetchAssociative($sql, $params, $types);

        if ($row === false) {
            return null;
        }

        return Post::fromRow(
            $row,
            $this->fetchCategoryIds((int) $row['id']),
            $this->fetchTagIds((int) $row['id']),
            $this->fetchMeta((int) $row['id']),
        );
    }

    /** @inheritDoc */
    public function findAll(Pagination $pagination, array $filters = []): array
    {
        [$where, $params, $types] = $this->buildWhere($filters);

        if ($pagination->sortBy === 'parent') {
            $dir     = strtoupper($pagination->sortDir) === 'ASC' ? 'ASC' : 'DESC';
            $orderBy = ' ORDER BY COALESCE((SELECT pp.title FROM ' . VL_DB_PREFIX . 'posts' . ' pp'
                . ' WHERE pp.id = ' . VL_DB_PREFIX . 'posts' . '.parent_id), \'\') ' . $dir . ', id DESC';
        } elseif ($pagination->sortBy === 'categories') {
            $dir     = strtoupper($pagination->sortDir) === 'ASC' ? 'ASC' : 'DESC';
            $orderBy = ' ORDER BY COALESCE((SELECT MIN(c.name) FROM ' . VL_DB_PREFIX . 'post_categories' . ' pcs'
                . ' JOIN ' . VL_DB_PREFIX . 'categories' . ' c ON c.id = pcs.category_id'
                . ' WHERE pcs.post_id = ' . VL_DB_PREFIX . 'posts' . '.id), \'\') ' . $dir . ', id DESC';
        } elseif ($pagination->sortBy === 'tags') {
            $dir     = strtoupper($pagination->sortDir) === 'ASC' ? 'ASC' : 'DESC';
            $orderBy = ' ORDER BY COALESCE((SELECT MIN(t.name) FROM ' . VL_DB_PREFIX . 'post_tags' . ' pts'
                . ' JOIN ' . VL_DB_PREFIX . 'tags' . ' t ON t.id = pts.tag_id'
                . ' WHERE pts.post_id = ' . VL_DB_PREFIX . 'posts' . '.id), \'\') ' . $dir . ', id DESC';
        } else {
            $orderBy = $pagination->orderByClause(
                ['id', 'title', 'status', 'created_at', 'published_at', 'updated_at', 'language', 'author_id'],
                'id DESC',
            );
        }
        $sql = 'SELECT * FROM ' . VL_DB_PREFIX . 'posts' . $where
             . $orderBy . ' LIMIT ? OFFSET ?';
        $params[] = $pagination->perPage;
        $params[] = $pagination->offset;
        $types[]  = ParameterType::INTEGER;
        $types[]  = ParameterType::INTEGER;

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        return $this->hydratePostRows($rows);
    }

    /** @inheritDoc */
    public function count(array $filters = []): int
    {
        [$where, $params, $types] = $this->buildWhere($filters);
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'posts' . $where,
            $params,
            $types,
        );
    }

    public function save(Post $post): Post
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'content_type'      => $post->getContentType(),
            'language'          => $post->getLanguage(),
            'translation_of'    => $post->getTranslationOf(),
            'author_id'         => $post->getAuthorId(),
            'title'             => $post->getTitle(),
            'slug'              => (string) $post->getSlug(),
            'excerpt'           => $post->getExcerpt(),
            'body'              => $post->getBody(),
            'status'            => $post->getStatus(),
            'featured_image_id' => $post->getFeaturedImageId(),
            'parent_id'         => $post->getParentId(),
            'sort_order'        => $post->getSortOrder(),
            'comment_status'    => $post->getCommentStatus(),
            'page_template'     => $post->getPageTemplate(),
            'hide_title'        => (int) $post->isHideTitle(),
            'meta_title'        => $post->getMetaTitle(),
            'meta_description'  => $post->getMetaDescription(),
            'published_at'      => $post->getPublishedAt()?->format('Y-m-d H:i:s'),
            'expires_at'        => $post->getExpiresAt()?->format('Y-m-d H:i:s'),
            'swap_post_id'      => $post->getSwapPostId(),
            'rss_exclude'       => (int) $post->isRssExcluded(),
            'access_tags'       => json_encode($post->getAccessTags()),
        ];

        if ($post->isNew()) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'posts', $data);
            $post->setId((int) $this->db->lastInsertId());
        } else {
            $data['updated_at'] = $now;
            $this->db->update(VL_DB_PREFIX . 'posts', $data, ['id' => $post->getId()]);
        }

        $this->syncCategories($post->getId(), $post->getCategoryIds());
        $this->syncTags($post->getId(), $post->getTagIds());
        $this->syncMeta($post->getId(), $post->getMeta());

        return $post;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'post_categories', ['post_id' => $id]);
        $this->db->delete(VL_DB_PREFIX . 'post_tags', ['post_id' => $id]);
        $this->db->delete(VL_DB_PREFIX . 'post_meta', ['post_id' => $id]);
        $this->db->delete(VL_DB_PREFIX . 'posts', ['id' => $id]);
    }

    public function slugExists(string $slug, string $contentType, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'posts' . ' WHERE slug = ? AND content_type = ?';
        $params = [$slug, $contentType];
        $types  = [ParameterType::STRING, ParameterType::STRING];

        if ($excludeId !== null) {
            $sql    .= ' AND id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        return (int) $this->db->fetchOne($sql, $params, $types) > 0;
    }

    /** @inheritDoc */
    public function findByCategorySlug(string $slug, Pagination $pagination, string $contentType = 'post', ?string $language = null, bool $fallback = false): array
    {
        $table      = VL_DB_PREFIX . 'posts';
        $langClause = '';
        $params     = [$slug, $contentType, 'published'];
        $types      = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];

        if ($language !== null) {
            if ($fallback) {
                $langClause = ' AND (p.language = ? OR (p.translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' t2
                    WHERE t2.translation_of = p.id AND t2.language = ?
                )))';
                $params[]   = $language;
                $params[]   = $language;
                $types[]    = ParameterType::STRING;
                $types[]    = ParameterType::STRING;
            } else {
                $langClause = ' AND p.language = ?';
                $params[]   = $language;
                $types[]    = ParameterType::STRING;
            }
        }

        $sql = 'SELECT p.* FROM ' . $table . ' p'
             . ' INNER JOIN ' . VL_DB_PREFIX . 'post_categories' . ' pc ON pc.post_id = p.id'
             . ' INNER JOIN ' . VL_DB_PREFIX . 'categories' . ' c ON c.id = pc.category_id AND c.slug = ?'
             . ' WHERE p.content_type = ? AND p.status = ?' . $langClause
             . ' ORDER BY p.published_at DESC LIMIT ? OFFSET ?';

        $params[] = $pagination->perPage;
        $params[] = $pagination->offset;
        $types[]  = ParameterType::INTEGER;
        $types[]  = ParameterType::INTEGER;

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        return $this->hydratePostRows($rows);
    }

    public function countByCategorySlug(string $slug, string $contentType = 'post', ?string $language = null, bool $fallback = false): int
    {
        $table      = VL_DB_PREFIX . 'posts';
        $langClause = '';
        $params     = [$slug, $contentType, 'published'];
        $types      = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];

        if ($language !== null) {
            if ($fallback) {
                $langClause = ' AND (p.language = ? OR (p.translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' t2
                    WHERE t2.translation_of = p.id AND t2.language = ?
                )))';
                $params[]   = $language;
                $params[]   = $language;
                $types[]    = ParameterType::STRING;
                $types[]    = ParameterType::STRING;
            } else {
                $langClause = ' AND p.language = ?';
                $params[]   = $language;
                $types[]    = ParameterType::STRING;
            }
        }

        $sql = 'SELECT COUNT(*) FROM ' . $table . ' p'
            . ' INNER JOIN ' . VL_DB_PREFIX . 'post_categories' . ' pc ON pc.post_id = p.id'
            . ' INNER JOIN ' . VL_DB_PREFIX . 'categories' . ' c ON c.id = pc.category_id AND c.slug = ?'
            . ' WHERE p.content_type = ? AND p.status = ?' . $langClause;

        return (int) $this->db->fetchOne($sql, $params, $types);
    }

    /** @inheritDoc */
    public function findByTagSlug(string $slug, Pagination $pagination, string $contentType = 'post', ?string $language = null, bool $fallback = false): array
    {
        $table      = VL_DB_PREFIX . 'posts';
        $langClause = '';
        $params     = [$slug, $contentType, 'published'];
        $types      = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];

        if ($language !== null) {
            if ($fallback) {
                $langClause = ' AND (p.language = ? OR (p.translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' t2
                    WHERE t2.translation_of = p.id AND t2.language = ?
                )))';
                $params[]   = $language;
                $params[]   = $language;
                $types[]    = ParameterType::STRING;
                $types[]    = ParameterType::STRING;
            } else {
                $langClause = ' AND p.language = ?';
                $params[]   = $language;
                $types[]    = ParameterType::STRING;
            }
        }

        $sql = 'SELECT p.* FROM ' . $table . ' p'
             . ' INNER JOIN ' . VL_DB_PREFIX . 'post_tags' . ' pt ON pt.post_id = p.id'
             . ' INNER JOIN ' . VL_DB_PREFIX . 'tags' . ' t ON t.id = pt.tag_id AND t.slug = ?'
             . ' WHERE p.content_type = ? AND p.status = ?' . $langClause
             . ' ORDER BY p.published_at DESC LIMIT ? OFFSET ?';

        $params[] = $pagination->perPage;
        $params[] = $pagination->offset;
        $types[]  = ParameterType::INTEGER;
        $types[]  = ParameterType::INTEGER;

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        return $this->hydratePostRows($rows);
    }

    public function countByTagSlug(string $slug, string $contentType = 'post', ?string $language = null, bool $fallback = false): int
    {
        $table      = VL_DB_PREFIX . 'posts';
        $langClause = '';
        $params     = [$slug, $contentType, 'published'];
        $types      = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];

        if ($language !== null) {
            if ($fallback) {
                $langClause = ' AND (p.language = ? OR (p.translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' t2
                    WHERE t2.translation_of = p.id AND t2.language = ?
                )))';
                $params[]   = $language;
                $params[]   = $language;
                $types[]    = ParameterType::STRING;
                $types[]    = ParameterType::STRING;
            } else {
                $langClause = ' AND p.language = ?';
                $params[]   = $language;
                $types[]    = ParameterType::STRING;
            }
        }

        $sql = 'SELECT COUNT(*) FROM ' . $table . ' p'
            . ' INNER JOIN ' . VL_DB_PREFIX . 'post_tags' . ' pt ON pt.post_id = p.id'
            . ' INNER JOIN ' . VL_DB_PREFIX . 'tags' . ' t ON t.id = pt.tag_id AND t.slug = ?'
            . ' WHERE p.content_type = ? AND p.status = ?' . $langClause;

        return (int) $this->db->fetchOne($sql, $params, $types);
    }

    public function findPagesByParent(?int $parentId, array $filters = []): array
    {
        $table  = VL_DB_PREFIX . 'posts';
        $sql    = 'SELECT * FROM ' . $table . ' WHERE content_type = ? AND status = ?';
        $params = ['page', 'published'];
        $types  = [ParameterType::STRING, ParameterType::STRING];

        if ($parentId === null) {
            $sql .= ' AND parent_id IS NULL';
        } else {
            $sql .= ' AND parent_id = ?';
            $params[] = $parentId;
            $types[]  = ParameterType::INTEGER;
        }

        if (isset($filters['language'])) {
            if (!empty($filters['language_fallback'])) {
                $sql .= ' AND (language = ? OR (translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' t2
                    WHERE t2.translation_of = ' . $table . '.id AND t2.language = ?
                )))';
                $params[] = $filters['language'];
                $params[] = $filters['language'];
                $types[]  = ParameterType::STRING;
                $types[]  = ParameterType::STRING;
            } else {
                $sql .= ' AND language = ?';
                $params[] = $filters['language'];
                $types[]  = ParameterType::STRING;
            }
        }

        $sql .= ' ORDER BY sort_order ASC, title ASC';

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        return $this->hydratePostRows($rows);
    }

    public function findPageByPath(string $path, ?string $language = null): ?Post
    {
        $segments = array_filter(explode('/', $path), fn (string $s) => $s !== '');
        if (empty($segments)) {
            return null;
        }

        $parentId = null;
        $page     = null;

        foreach ($segments as $segment) {
            $sql = 'SELECT * FROM ' . VL_DB_PREFIX . 'posts'
                 . ' WHERE slug = ? AND content_type = ?';
            $params = [$segment, 'page'];
            $types  = [ParameterType::STRING, ParameterType::STRING];

            if ($language !== null) {
                $sql .= ' AND language = ?';
                $params[] = $language;
                $types[]  = ParameterType::STRING;
            }

            $sql .= ' AND ';

            if ($parentId === null) {
                $sql .= 'parent_id IS NULL';
            } else {
                $sql .= 'parent_id = ?';
                $params[] = $parentId;
                $types[]  = ParameterType::INTEGER;
            }

            $row = $this->db->fetchAssociative($sql, $params, $types);
            if ($row === false) {
                return null;
            }

            $page = Post::fromRow(
                $row,
                $this->fetchCategoryIds((int) $row['id']),
                $this->fetchTagIds((int) $row['id']),
                $this->fetchMeta((int) $row['id']),
            );
            $parentId = (int) $row['id'];
        }

        return $page;
    }

    public function buildPagePath(Post $page): string
    {
        $segments = [(string) $page->getSlug()];
        $parentId = $page->getParentId();

        // Walk up the parent chain (max 10 levels to prevent infinite loops)
        $i = 0;
        while ($parentId !== null && $i++ < 10) {
            $row = $this->db->fetchAssociative(
                'SELECT id, slug, parent_id FROM ' . VL_DB_PREFIX . 'posts' . ' WHERE id = ?',
                [$parentId],
                [ParameterType::INTEGER],
            );
            if ($row === false) {
                break;
            }
            array_unshift($segments, $row['slug']);
            $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        }

        return implode('/', $segments);
    }

    // ── Pivot helpers ────────────────────────────────────────────────

    /**
     * Hydrate an array of raw DB rows into Post entities with batch-loaded
     * category and tag IDs (avoids N+1 queries).
     *
     * @param  array<array<string,mixed>> $rows
     * @return Post[]
     */
    private function hydratePostRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $postIds = array_map(fn (array $r) => (int) $r['id'], $rows);
        $catMap  = $this->batchFetchCategoryIds($postIds);
        $tagMap  = $this->batchFetchTagIds($postIds);
        $metaMap = $this->batchFetchMeta($postIds);

        return array_map(fn (array $row) => Post::fromRow(
            $row,
            $catMap[(int) $row['id']]  ?? [],
            $tagMap[(int) $row['id']]  ?? [],
            $metaMap[(int) $row['id']] ?? [],
        ), $rows);
    }

    private function hydrateTranslatedLanguages(array $posts, array $originalIds): void
    {
        if (empty($originalIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($originalIds), '?'));
        $types        = array_fill(0, count($originalIds), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT translation_of, language FROM ' . VL_DB_PREFIX . 'posts WHERE translation_of IN (' . $placeholders . ')',
            $originalIds,
            $types
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['translation_of']][] = (string) $r['language'];
        }
        foreach ($posts as $post) {
            $post->setTranslatedLanguages($map[(int) $post->getId()] ?? []);
        }
    }

    /** @return array<int, int[]> postId => categoryId[] */
    private function batchFetchCategoryIds(array $postIds): array
    {
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $types        = array_fill(0, count($postIds), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT post_id, category_id FROM ' . VL_DB_PREFIX . 'post_categories'
            . " WHERE post_id IN ($placeholders)",
            $postIds,
            $types,
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['post_id']][] = (int) $row['category_id'];
        }
        return $map;
    }

    /** @return array<int, array<string, mixed>> postId => [key => value] */
    private function batchFetchMeta(array $postIds): array
    {
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $types        = array_fill(0, count($postIds), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT post_id, meta_key, meta_value FROM ' . VL_DB_PREFIX . 'post_meta'
            . " WHERE post_id IN ($placeholders)",
            $postIds,
            $types,
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['post_id']][$row['meta_key']] = $row['meta_value'];
        }
        return $map;
    }

    /** @return array<int, int[]> postId => tagId[] */
    private function batchFetchTagIds(array $postIds): array
    {
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $types        = array_fill(0, count($postIds), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT post_id, tag_id FROM ' . VL_DB_PREFIX . 'post_tags'
            . " WHERE post_id IN ($placeholders)",
            $postIds,
            $types,
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['post_id']][] = (int) $row['tag_id'];
        }
        return $map;
    }

    /** @return int[] */
    private function fetchCategoryIds(int $postId): array
    {
        return array_map('intval', $this->db->fetchFirstColumn(
            'SELECT category_id FROM ' . VL_DB_PREFIX . 'post_categories' . ' WHERE post_id = ?',
            [$postId],
            [ParameterType::INTEGER],
        ));
    }

    /** @return array<string, mixed> */
    private function fetchMeta(int $postId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT meta_key, meta_value FROM ' . VL_DB_PREFIX . 'post_meta' . ' WHERE post_id = ?',
            [$postId],
            [ParameterType::INTEGER],
        );
        $meta = [];
        foreach ($rows as $row) {
            $meta[$row['meta_key']] = $row['meta_value'];
        }
        return $meta;
    }

    /** @return int[] */
    private function fetchTagIds(int $postId): array
    {
        return array_map('intval', $this->db->fetchFirstColumn(
            'SELECT tag_id FROM ' . VL_DB_PREFIX . 'post_tags' . ' WHERE post_id = ?',
            [$postId],
            [ParameterType::INTEGER],
        ));
    }

    /** @param int[] $categoryIds */
    private function syncCategories(int $postId, array $categoryIds): void
    {
        $this->db->delete(VL_DB_PREFIX . 'post_categories', ['post_id' => $postId]);
        foreach ($categoryIds as $catId) {
            $this->db->insert(VL_DB_PREFIX . 'post_categories', [
                'post_id'     => $postId,
                'category_id' => $catId,
            ]);
        }
    }

    /** @param array<string, mixed> $meta */
    private function syncMeta(int $postId, array $meta): void
    {
        $this->db->delete(VL_DB_PREFIX . 'post_meta', ['post_id' => $postId]);
        foreach ($meta as $key => $value) {
            if ($value === null) {
                continue;
            }
            $this->db->insert(VL_DB_PREFIX . 'post_meta', [
                'post_id'    => $postId,
                'meta_key'   => $key,
                'meta_value' => is_scalar($value) ? (string) $value : json_encode($value),
            ]);
        }
    }

    /** @param int[] $tagIds */
    private function syncTags(int $postId, array $tagIds): void
    {
        $this->db->delete(VL_DB_PREFIX . 'post_tags', ['post_id' => $postId]);
        foreach ($tagIds as $tagId) {
            $this->db->insert(VL_DB_PREFIX . 'post_tags', [
                'post_id' => $postId,
                'tag_id'  => $tagId,
            ]);
        }
    }

    public function updateContentType(string $oldType, string $newType): void
    {
        $this->db->update(
            VL_DB_PREFIX . 'posts',
            ['content_type' => $newType],
            ['content_type' => $oldType],
        );
    }

    /** @inheritDoc */
    public function findRelated(Post $post, int $limit = 3, ?string $language = null, bool $fallback = false): array
    {
        $p          = VL_DB_PREFIX;
        $catIds     = $post->getCategoryIds();
        $tagIds     = $post->getTagIds();
        $exclude    = [$post->getId()];
        $result     = [];
        $langClause = '';
        $langParams = [];
        $langTypes  = [];

        if ($language !== null) {
            if ($fallback) {
                $langClause = " AND (p.language = ? OR (p.translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM {$p}posts t2
                    WHERE t2.translation_of = p.id AND t2.language = ?
                )))";
                $langParams = [$language, $language];
                $langTypes  = [ParameterType::STRING, ParameterType::STRING];
            } else {
                $langClause = ' AND p.language = ?';
                $langParams = [$language];
                $langTypes  = [ParameterType::STRING];
            }
        }

        // 1) Try categories
        if (!empty($catIds)) {
            $placeholders  = implode(',', array_fill(0, count($catIds), '?'));
            $types         = array_fill(0, count($catIds), ParameterType::INTEGER);
            $rows          = $this->db->fetchAllAssociative(
                "SELECT DISTINCT p.* FROM {$p}posts p"
                . " JOIN {$p}post_categories pc ON pc.post_id = p.id"
                . " WHERE pc.category_id IN ($placeholders)"
                . " AND p.id != ? AND p.status = 'published' AND p.content_type = ?"
                . $langClause
                . ' ORDER BY p.published_at DESC LIMIT ?',
                [...$catIds, $exclude[0], $post->getContentType(), ...$langParams, $limit],
                [...$types, ParameterType::INTEGER, ParameterType::STRING, ...$langTypes, ParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $result[] = Post::fromRow(
                    $row,
                    $this->fetchCategoryIds((int) $row['id']),
                    $this->fetchTagIds((int) $row['id']),
                    $this->fetchMeta((int) $row['id']),
                );
                $exclude[] = (int) $row['id'];
            }
        }

        // 2) If still need more, try tags (exclude already found)
        $need = $limit - count($result);
        if ($need > 0 && !empty($tagIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($exclude), '?'));
            $tagPlaceholders     = implode(',', array_fill(0, count($tagIds), '?'));
            $types               = [...array_fill(0, count($tagIds), ParameterType::INTEGER), ...array_fill(0, count($exclude), ParameterType::INTEGER), ParameterType::STRING, ...$langTypes, ParameterType::INTEGER];
            $rows                = $this->db->fetchAllAssociative(
                "SELECT DISTINCT p.* FROM {$p}posts p"
                . " JOIN {$p}post_tags pt ON pt.post_id = p.id"
                . " WHERE pt.tag_id IN ($tagPlaceholders)"
                . " AND p.id NOT IN ($excludePlaceholders)"
                . " AND p.status = 'published' AND p.content_type = ?"
                . $langClause
                . ' ORDER BY p.published_at DESC LIMIT ?',
                [...$tagIds, ...$exclude, $post->getContentType(), ...$langParams, $need],
                $types,
            );
            foreach ($rows as $row) {
                $result[] = Post::fromRow(
                    $row,
                    $this->fetchCategoryIds((int) $row['id']),
                    $this->fetchTagIds((int) $row['id']),
                    $this->fetchMeta((int) $row['id']),
                );
            }
        }

        return $result;
    }

    /**
     * @param array{content_type?: string, status?: string, author_id?: int, search?: string} $filters
     * @return array{string, array<mixed>, array<ParameterType>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];
        $types   = [];
        $table   = VL_DB_PREFIX . 'posts';

        if (isset($filters['language'])) {
            if (!empty($filters['language_fallback'])) {
                $clauses[] = '(language = ? OR (translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' t2
                    WHERE t2.translation_of = ' . $table . '.id AND t2.language = ?
                )))';
                $params[]  = $filters['language'];
                $params[]  = $filters['language'];
                $types[]   = ParameterType::STRING;
                $types[]   = ParameterType::STRING;
            } else {
                $clauses[] = 'language = ?';
                $params[]  = $filters['language'];
                $types[]   = ParameterType::STRING;
            }
        }

        if (isset($filters['translation_of'])) {
            $clauses[] = 'translation_of = ?';
            $params[]  = (int) $filters['translation_of'];
            $types[]   = ParameterType::INTEGER;
        }

        if (!empty($filters['content_type'])) {
            $clauses[] = 'content_type = ?';
            $params[]  = $filters['content_type'];
            $types[]   = ParameterType::STRING;
        }

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(', ', array_fill(0, count($filters['status']), '?'));
                $clauses[]    = 'status IN (' . $placeholders . ')';
                $params       = array_merge($params, $filters['status']);
                $types        = array_merge($types, array_fill(0, count($filters['status']), ParameterType::STRING));
            } else {
                $clauses[] = 'status = ?';
                $params[]  = $filters['status'];
                $types[]   = ParameterType::STRING;
            }
        }

        if (!empty($filters['author_id'])) {
            $clauses[] = 'author_id = ?';
            $params[]  = (int) $filters['author_id'];
            $types[]   = ParameterType::INTEGER;
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $ors  = [
                'LOWER(title) LIKE LOWER(?)',
                'LOWER(COALESCE(excerpt, \'\')) LIKE LOWER(?)',
                'LOWER(slug) LIKE LOWER(?)',
            ];
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
            // Only page listings show a Parent column, so only their search
            // resolves parent page titles — the posts listing stays unchanged.
            if (($filters['content_type'] ?? '') === 'page') {
                $ors[] = 'EXISTS (
                    SELECT 1 FROM ' . $table . ' parent_post
                    WHERE parent_post.id = ' . $table . '.parent_id AND LOWER(parent_post.title) LIKE LOWER(?))';
                $params[] = $term;
                $types[]  = ParameterType::STRING;
            }
            // Mirror image: the author, category and tag columns only exist on
            // post-style listings, so page listings keep their search focused.
            if (($filters['content_type'] ?? '') !== 'page') {
                $ors[] = 'EXISTS (
                    SELECT 1 FROM ' . VL_DB_PREFIX . 'users' . ' u
                    WHERE u.id = ' . $table . '.author_id AND LOWER(u.name) LIKE LOWER(?))';
                $ors[] = 'EXISTS (
                    SELECT 1 FROM ' . VL_DB_PREFIX . 'post_categories' . ' pcs
                    JOIN ' . VL_DB_PREFIX . 'categories' . ' c ON c.id = pcs.category_id
                    WHERE pcs.post_id = ' . $table . '.id AND LOWER(c.name) LIKE LOWER(?))';
                $ors[] = 'EXISTS (
                    SELECT 1 FROM ' . VL_DB_PREFIX . 'post_tags' . ' pts
                    JOIN ' . VL_DB_PREFIX . 'tags' . ' t ON t.id = pts.tag_id
                    WHERE pts.post_id = ' . $table . '.id AND LOWER(t.name) LIKE LOWER(?))';
                $params[] = $term;
                $params[] = $term;
                $params[] = $term;
                $types[]  = ParameterType::STRING;
                $types[]  = ParameterType::STRING;
                $types[]  = ParameterType::STRING;
            }
            if (!empty($filters['search_body'])) {
                $ors[]    = 'LOWER(COALESCE(body, \'\')) LIKE LOWER(?)';
                $params[] = $term;
                $types[]  = ParameterType::STRING;
            }
            $clauses[] = '(' . implode(' OR ', $ors) . ')';
        }

        if (!empty($filters['tag_ids']) && is_array($filters['tag_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filters['tag_ids']), '?'));
            $clauses[]    = 'EXISTS (
                SELECT 1 FROM ' . VL_DB_PREFIX . 'post_tags' . ' pt
                WHERE pt.post_id = ' . $table . '.id AND pt.tag_id IN (' . $placeholders . '))';
            foreach ($filters['tag_ids'] as $tid) {
                $params[] = (int) $tid;
                $types[]  = ParameterType::INTEGER;
            }
        } elseif (!empty($filters['tag_id'])) {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM ' . VL_DB_PREFIX . 'post_tags' . ' pt
                WHERE pt.post_id = ' . $table . '.id AND pt.tag_id = ?)';
            $params[]  = (int) $filters['tag_id'];
            $types[]   = ParameterType::INTEGER;
        }

        if (!empty($filters['category_ids']) && is_array($filters['category_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filters['category_ids']), '?'));
            $clauses[]    = 'EXISTS (
                SELECT 1 FROM ' . VL_DB_PREFIX . 'post_categories' . ' pc
                WHERE pc.post_id = ' . $table . '.id AND pc.category_id IN (' . $placeholders . '))';
            foreach ($filters['category_ids'] as $cid) {
                $params[] = (int) $cid;
                $types[]  = ParameterType::INTEGER;
            }
        } elseif (!empty($filters['category_id'])) {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM ' . VL_DB_PREFIX . 'post_categories' . ' pc
                WHERE pc.post_id = ' . $table . '.id AND pc.category_id = ?)';
            $params[]  = (int) $filters['category_id'];
            $types[]   = ParameterType::INTEGER;
        }

        if (!empty($filters['category'])) {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM ' . VL_DB_PREFIX . 'post_categories' . ' pc
                JOIN ' . VL_DB_PREFIX . 'categories' . ' c ON c.id = pc.category_id
                WHERE pc.post_id = ' . $table . '.id
                  AND (LOWER(c.slug) = LOWER(?) OR LOWER(c.name) = LOWER(?)))';
            $params[] = (string) $filters['category'];
            $params[] = (string) $filters['category'];
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
        }

        if (!empty($filters['tag'])) {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM ' . VL_DB_PREFIX . 'post_tags' . ' pt
                JOIN ' . VL_DB_PREFIX . 'tags' . ' t ON t.id = pt.tag_id
                WHERE pt.post_id = ' . $table . '.id
                  AND (LOWER(t.slug) = LOWER(?) OR LOWER(t.name) = LOWER(?)))';
            $params[] = (string) $filters['tag'];
            $params[] = (string) $filters['tag'];
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
        }

        $where = $clauses ? ' WHERE ' . implode(' AND ', $clauses) : '';
        return [$where, $params, $types];
    }

    /** @return Post[] */
    public function findDueForPublishing(): array
    {
        $sql  = 'SELECT * FROM ' . VL_DB_PREFIX . 'posts' . " WHERE status = 'scheduled' AND published_at <= ?";
        $rows = $this->db->fetchAllAssociative($sql, [(new \DateTimeImmutable())->format('Y-m-d H:i:s')], [ParameterType::STRING]);
        return $this->hydratePostRows($rows);
    }

    /** @return Post[] */
    public function findDueForExpiry(): array
    {
        $sql  = 'SELECT * FROM ' . VL_DB_PREFIX . 'posts' . " WHERE status IN ('published', 'unlisted') AND expires_at <= ?";
        $rows = $this->db->fetchAllAssociative($sql, [(new \DateTimeImmutable())->format('Y-m-d H:i:s')], [ParameterType::STRING]);
        return $this->hydratePostRows($rows);
    }

    /** @return Post[] */
    public function findDueForGoPublic(): array
    {
        $sql  = 'SELECT * FROM ' . VL_DB_PREFIX . 'posts' . " WHERE status = 'unlisted' AND published_at IS NOT NULL AND published_at <= ?";
        $rows = $this->db->fetchAllAssociative($sql, [(new \DateTimeImmutable())->format('Y-m-d H:i:s')], [ParameterType::STRING]);
        return $this->hydratePostRows($rows);
    }

    public function performSwap(Post $expiring, Post $partner, Slug $archiveSlug): void
    {
        $liveSlug = (string) $expiring->getSlug();

        $this->db->transactional(function () use ($expiring, $partner, $archiveSlug, $liveSlug): void {
            $expiring->setSlug($archiveSlug);
            $expiring->expire();
            $this->save($expiring);

            $partner->setSlug(new Slug($liveSlug));
            $partner->publish();
            $this->save($partner);
        });
    }

    /** @inheritDoc */
    public function findPotentialOriginals(string $contentType, ?int $excludeId = null, ?string $language = null): array
    {
        $p      = VL_DB_PREFIX;
        $select = 'SELECT p.*';
        $params = [];
        $types  = [];

        if ($language !== null) {
            $select .= ', CASE WHEN (p.language = ? OR EXISTS ('
                . 'SELECT 1 FROM ' . $p . 'posts t'
                . ' WHERE t.translation_of = p.id AND t.language = ?'
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

        $sql      = $select . ' FROM ' . $p . 'posts p'
            . ' WHERE p.content_type = ? AND p.translation_of IS NULL';
        $params[] = $contentType;
        $types[]  = ParameterType::STRING;

        if ($excludeId !== null) {
            $sql     .= ' AND p.id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        $sql .= ' ORDER BY p.title ASC LIMIT 500';
        $rows  = $this->db->fetchAllAssociative($sql, $params, $types);
        $posts = $this->hydratePostRows($rows);
        $this->hydrateTranslatedLanguages($posts, array_map(fn (array $r) => (int) $r['id'], $rows));
        return $posts;
    }

    /** @inheritDoc */
    public function findTranslations(int $originalId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'posts' . ' WHERE id = ? OR translation_of = ? ORDER BY language ASC',
            [$originalId, $originalId],
            [ParameterType::INTEGER, ParameterType::INTEGER]
        );
        return $this->hydratePostRows($rows);
    }

    /** @inheritDoc */
    public function countByPageTemplate(string $templateName): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'posts' . ' WHERE page_template = ?',
            [$templateName],
            [ParameterType::STRING]
        );
    }
}
