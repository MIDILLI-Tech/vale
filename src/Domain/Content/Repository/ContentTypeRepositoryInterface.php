<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content\Repository;

use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Shared\ValueObject\Slug;

interface ContentTypeRepositoryInterface
{
    public function findById(int $id): ?ContentType;
    public function findBySlug(Slug $slug): ?ContentType;
    /** @return ContentType[] */
    public function findAll(): array;
    /** @return ContentType[] */
    public function findCustom(): array;
    /** @return ContentType[] */
    public function findActiveCustom(): array;
    public function save(ContentType $type): ContentType;
    public function delete(int $id): void;
    public function slugExists(string $slug, ?int $excludeId = null): bool;
}
