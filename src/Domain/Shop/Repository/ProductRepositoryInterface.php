<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Repository;

use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;

    /** @param int[] $ids @return Product[] */
    public function findByIds(array $ids): array;

    public function findBySlug(Slug $slug, ?string $language = null): ?Product;

    public function findBySku(string $sku): ?Product;

    /**
     * Search matches name and SKU. Description fields are only included
     * when the search_body filter is set (admin opt-in).
     * @return Product[] */
    public function findAll(Pagination $pagination, array $filters = []): array;

    /**
     * Search matches name and SKU. Description fields are only included
     * when the search_body filter is set (admin opt-in). */
    public function count(array $filters = []): int;

    public function save(Product $product): Product;

    public function delete(int $id): void;

    /**
     * Products with status='scheduled' AND published_at <= NOW().
     * @return Product[]
     */
    public function findDueForPublishing(): array;

    /**
     * Products with status='published' or 'unlisted' AND expires_at <= NOW().
     * @return Product[]
     */
    public function findDueForExpiry(): array;

    /**
     * Products with status='unlisted' AND published_at IS NOT NULL AND <= NOW().
     * @return Product[]
     */
    public function findDueForGoPublic(): array;

    /**
     * Returns ['average' => float, 'count' => int] for a product.
     * @return array{average: float, count: int}
     */
    public function getVoteSummary(int $productId): array;

    /** @return array<int, array<string, mixed>> */
    public function findVariationsByProductId(int $productId): array;

    /**
     * Batched variation fetch grouped by product id (raw rows, no download ids).
     *
     * @param array<int, int> $productIds
     * @return array<int, array<int, array<string, mixed>>> productId => variations[]
     */
    public function findVariationsByProductIds(array $productIds): array;

    public function saveVariations(int $productId, array $variations): void;

    public function deleteVariation(int $variationId): void;

    /**
     * Atomically decrement stock for a product (or variation) by the given quantity.
     * No-op for unlimited products (in_stock with qty = 0).
     */
    public function decrementStock(int $productId, int $qty, ?int $variationId = null): void;

    /**
     * Atomically increment stock for a product (or variation) by the given quantity.
     */
    public function incrementStock(int $productId, int $qty, ?int $variationId = null): void;

    /**
     * Atomically increment the total_sold counter for a product.
     */
    public function incrementSold(int $productId, int $qty): void;

    /**
     * Atomically decrement the total_sold counter for a product.
     */
    public function decrementSold(int $productId, int $qty): void;

    /** @return int[] Ordered list of media IDs in the product gallery */
    public function findGalleryImageIds(int $productId): array;

    /** @param int[] $mediaIds Ordered list of media IDs */
    public function saveGalleryImages(int $productId, array $mediaIds): void;

    /** @return int[] Ordered list of media IDs for downloadable product files */
    public function findDownloadFileIds(int $productId): array;

    /** @param int[] $mediaIds Ordered list of media IDs */
    public function saveDownloadFileIds(int $productId, array $mediaIds): void;

    /** @return int[] Ordered list of media IDs for a variation downloadable files */
    public function findVariationDownloadFileIds(int $variationId): array;

    /** @return Product[] */
    public function findPotentialOriginals(?int $excludeId = null, ?string $language = null): array;

    /** @return Product[] All translations linked to a given original product ID (including the original) */
    public function findTranslations(int $originalId): array;
}
