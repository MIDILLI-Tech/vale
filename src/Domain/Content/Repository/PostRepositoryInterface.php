<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content\Repository;

use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;

interface PostRepositoryInterface
{
    public function findById(int $id): ?Post;

    public function findBySlug(Slug $slug, string $contentType = 'post', ?string $language = null): ?Post;

    /**
     * Search matches title, excerpt and slug. Body (LONGTEXT) is only
     * included when the search_body filter is set (admin opt-in).
     * @param array{content_type?: string, status?: string, author_id?: int, search?: string, search_body?: bool} $filters
     * @return Post[]
     */
    public function findAll(Pagination $pagination, array $filters = []): array;

    /**
     * Search matches title, excerpt and slug. Body (LONGTEXT) is only
     * included when the search_body filter is set (admin opt-in).
     * @param array{content_type?: string, status?: string, author_id?: int, search?: string, search_body?: bool} $filters */
    public function count(array $filters = []): int;

    public function save(Post $post): Post;

    public function delete(int $id): void;

    public function slugExists(string $slug, string $contentType, ?int $excludeId = null): bool;

    /**
     * @return Post[]
     */
    public function findByCategorySlug(string $slug, Pagination $pagination, string $contentType = 'post', ?string $language = null, bool $fallback = false): array;

    public function countByCategorySlug(string $slug, string $contentType = 'post', ?string $language = null, bool $fallback = false): int;

    /**
     * @return Post[]
     */
    public function findByTagSlug(string $slug, Pagination $pagination, string $contentType = 'post', ?string $language = null, bool $fallback = false): array;

    public function countByTagSlug(string $slug, string $contentType = 'post', ?string $language = null, bool $fallback = false): int;

    /**
     * @param array{content_type?: string, status?: string} $filters
     * @return Post[]
     */
    public function findPagesByParent(?int $parentId, array $filters = []): array;

    /**
     * Resolve a page by its full path (e.g. "parent-slug/child-slug").
     * Returns null if any segment is missing or the chain is broken.
     */
    public function findPageByPath(string $path, ?string $language = null): ?Post;

    /** @return Post[] */
    public function findPotentialOriginals(string $contentType, ?int $excludeId = null, ?string $language = null): array;

    /**
     * Build the full URL path for a page, walking up the parent chain.
     * Returns e.g. "parent-slug/child-slug".
     */
    public function buildPagePath(Post $page): string;

    /**
          * Return posts with status='scheduled' and published_at <= NOW().
          *
          * @return Post[]
          */
    public function findDueForPublishing(): array;

    /**
     * Return published or unlisted posts with expires_at <= NOW().
     *
     * @return Post[]
     */
    public function findDueForExpiry(): array;

    /**
     * Return unlisted posts with a reached publish date
     * (published_at IS NOT NULL AND <= NOW()).
     *
     * @return Post[]
     */
    public function findDueForGoPublic(): array;

    /**
     * Atomically exchange the live slot between two records: the expiring
     * record vacates to $archiveSlug and goes to draft, while the partner
     * takes over the expiring record's slug and goes published.
     */
    public function performSwap(Post $expiring, Post $partner, Slug $archiveSlug): void;

    /**
     * Update content_type for all posts of a given type.
     * Used when slug is renamed.
     */
    public function updateContentType(string $oldType, string $newType): void;

    /**
     * Find related posts sharing categories or tags with the given post.
     * Tries categories first; fills remaining slots with tag-matched posts.
     *
     * @return Post[]
     */
    public function findRelated(Post $post, int $limit = 3, ?string $language = null, bool $fallback = false): array;

    /** @return Post[] All translations linked to a given original post ID (including the original) */
    public function findTranslations(int $originalId): array;

    /** Count how many posts/pages use a specific page template name */
    public function countByPageTemplate(string $templateName): int;
}
