<?php

declare(strict_types=1);

namespace ValeApp\Domain\Media\Repository;

use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Domain\Shared\ValueObject\Pagination;

interface MediaRepositoryInterface
{
    public function findById(int $id): ?Media;

    /** Find a media record by its stored relative path (e.g. 2025/01/photo.jpg). */
    public function findByPath(string $path): ?Media;

    /** Find a media record by its content hash (SHA-256). */
    public function findByContentHash(string $hash): ?Media;

    /** Find media records by original filename (multiple records possible). */
    public function findByOriginalName(string $originalName): array;

    /**
     * @param array{mime_type?: string, user_id?: int, search?: string, ids?: int[]} $filters
     * @return Media[]
     */
    public function findAll(Pagination $pagination, array $filters = []): array;

    /** @param array{mime_type?: string, user_id?: int, search?: string, ids?: int[]} $filters */
    public function count(array $filters = []): int;

    public function save(Media $media): int;

    public function delete(int $id): void;

    /** Total file size in bytes for a given user (quota tracking). */
    public function getTotalSizeByUser(int $userId): int;

    /**
     * Find media records where the physical file is missing on disk.
     * @return array<int, array{id: int, path: string, original_name: string, size: int, mime_type: string}>
     */
    public function findMissingFiles(): array;

    /**
     * Find media records not referenced by any content (no FK, not in body HTML).
     * @return array<int, array{id: int, path: string, original_name: string, size: int, mime_type: string}>
     */
    public function findUnused(): array;

    /**
     * Media IDs referenced by any content (post/product featured images,
     * product images, product download files).
     *
     * @return array<int, true> Map keyed by media ID for O(1) lookups.
     */
    public function findReferencedIds(): array;
}
