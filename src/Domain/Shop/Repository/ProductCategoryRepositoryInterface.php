<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Repository;

use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\ProductCategory;

interface ProductCategoryRepositoryInterface
{
    public function findById(int $id): ?ProductCategory;

    public function findBySlug(Slug $slug, ?string $language = null): ?ProductCategory;

    /** @return ProductCategory[] */
    public function findAll(?string $language = null, bool $fallback = false): array;

    /** @return array<int, int> category_id => count */
    public function countUsage(): array;

    public function save(ProductCategory $category): ProductCategory;

    public function delete(int $id): void;

    /** @return ProductCategory[] */
    public function findPotentialOriginals(?int $excludeId = null, ?string $language = null): array;

    /** @return ProductCategory[] */
    public function findTranslations(int $originalId): array;

    /**
     * Original + translations for several roots in one query.
     *
     * @param int[] $rootIds
     * @return array<int, ProductCategory[]> keyed by root id
     */
    public function findTranslationsForRoots(array $rootIds): array;
}
