<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content\Repository;

use ValeApp\Domain\Content\Entity\Tag;
use ValeApp\Domain\Shared\ValueObject\Pagination;

interface TagRepositoryInterface
{
    public function findById(int $id): ?Tag;
    public function findByName(string $name, ?string $contentType = null): ?Tag;

    /**
     * @param string|null $contentType Filter by content type slug. Null = all.
     * @param string|null $language    Filter by language code.
     * @return Tag[]
     */
    public function findAll(?Pagination $pagination = null, ?string $contentType = null, ?string $language = null, bool $fallback = false): array;

    /**
     * @param string|null $contentType Filter by content type slug. Null = all.
     * @param string|null $language    Filter by language code.
     * @param string|null $search      Filter by tag name or slug (LIKE).
     * @return array<array{tag: Tag, usageCount: int}>
     */
    public function findAllWithUsageCount(
        ?Pagination $pagination = null,
        ?string $contentType = null,
        ?string $language = null,
        bool $fallback = false,
        ?string $search = null,
    ): array;

    public function count(
        ?string $contentType = null,
        ?string $language = null,
        bool $fallback = false,
        ?string $search = null,
    ): int;
    public function save(Tag $tag): Tag;
    public function delete(int $id): void;

    public function findBySlug(string $slug, ?string $contentType = null, ?string $language = null): ?Tag;

    /**
     * Find or create tags by name. Scoped to a content type so the same name
     * in different content types does not collide.
     * @param string[] $names
     * @param string|null $language Language code for newly created tags. Null defaults to 'en'.
     * @return Tag[]
     */
    public function findOrCreateByNames(array $names, string $contentType = 'post', ?string $language = null): array;

    /**
     * @return array<array{id: int, title: string, contentType: string, status: string, createdAt: string}>
     */
    public function findPostsByTagId(int $tagId): array;

    /** @return Tag[] */
    public function findPotentialOriginals(
        ?string $contentType = null,
        ?int $excludeId = null,
        ?string $language = null,
    ): array;

    /** @return Tag[] */
    public function findTranslations(int $originalId): array;

    /**
     * Original + translations for several roots in one query.
     *
     * @param int[] $rootIds
     * @return array<int, Tag[]> keyed by root id
     */
    public function findTranslationsForRoots(array $rootIds): array;

    /**
     * @param string|null $contentType Filter by content type slug. Null = all.
     * @return array<int, int> tagId => usageCount (only published posts)
     */
    public function getUsageCounts(?string $contentType = null): array;

    /**
     * Map original tag names to their translated equivalents in a given language.
     * Resolves the original (root) for each name first, then finds the translation.
     * Falls back to the original name when no translation exists.
     * @param string[] $names
     * @return string[]
     */
    public function findTranslatedNames(array $names, string $language): array;
}
