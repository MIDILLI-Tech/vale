<?php

declare(strict_types=1);

namespace ValeApp\Domain\Seo\Repository;

use ValeApp\Domain\Seo\Entity\SeoMeta;

interface SeoMetaRepositoryInterface
{
    public function findByEntity(string $entityType, int $entityId): ?SeoMeta;

    public function save(SeoMeta $seo): SeoMeta;

    public function deleteByEntity(string $entityType, int $entityId): void;

    /**
     * Fetch all published entries for sitemap generation.
     * Returns arrays of [slug, updated_at, entity_type] for content with robots allowing indexing.
     *
     * @return array<int, array{slug: string, updated_at: string|null, entity_type: string, entity_id: int}>
     */
    public function findIndexableEntries(): array;
}
