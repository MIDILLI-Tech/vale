<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content\Repository;

use ValeApp\Domain\Content\Entity\Category;
use ValeApp\Domain\Shared\ValueObject\Pagination;

interface CategoryRepositoryInterface
{
    public function findById(int $id): ?Category;

    /**
     * @param string|null $contentType Filter by content type slug. Null = all.
     * @param string|null $language    Filter by language code.
     * @return Category[]
     */
    public function findAll(?Pagination $pagination = null, ?string $contentType = null, ?string $language = null, bool $fallback = false): array;

    public function count(?string $contentType = null, ?string $language = null, bool $fallback = false): int;

    public function save(Category $category): Category;

    public function delete(int $id): void;

    public function slugExists(string $slug, ?int $excludeId = null, ?string $contentType = null): bool;

    public function findBySlug(string $slug, ?string $contentType = null, ?string $language = null): ?Category;

    /**
     * @param string|null $contentType Filter by content type slug. Null = all.
     * @return array<int, int> categoryId => usageCount
     */
    public function getUsageCounts(?string $contentType = null): array;

    /** @return array<array{id:int, title:string, status:string, createdAt:string, contentType:string}> */
    public function findPostsByCategoryId(int $categoryId): array;

    /** @return Category[] */
    public function findPotentialOriginals(
        ?string $contentType = null,
        ?int $excludeId = null,
        ?string $language = null,
    ): array;

    /** @return Category[] */
    public function findTranslations(int $originalId): array;

    /**
     * Original + translations for several roots in one query.
     *
     * @param int[] $rootIds
     * @return array<int, Category[]> keyed by root id
     */
    public function findTranslationsForRoots(array $rootIds): array;

    /**
     * Map original category IDs to their translated equivalents in a given language.
     * Resolves the original (root) for each ID first, then finds translations.
     * @param int[] $ids
     * @return int[]
     */
    public function findTranslatedIds(array $ids, string $language): array;
}
