<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content\Repository;

use ValeApp\Domain\Content\Entity\Revision;

interface RevisionRepositoryInterface
{
    public function save(Revision $revision): Revision;

    /** @return Revision[] */
    public function findByPostId(int $postId): array;

    public function findById(int $id): ?Revision;

    public function countByPostId(int $postId): int;

    public function nextRevisionNumber(int $postId): int;

    public function deleteOldestByPostId(int $postId, int $keepCount): void;

    /** Prune revisions, keeping only the latest $keepCount per item. Null $contentType prunes all types. Returns total deleted. */
    public function pruneAll(int $keepCount, ?string $contentType = null): int;

    /** Number of revisions pruneAll($keepCount, $contentType) would delete, without deleting anything. */
    public function countPrunable(int $keepCount, ?string $contentType = null): int;

    public function deleteByPostId(int $postId): void;

    public function deleteById(int $id): void;

    public function findPreviousByPostId(int $postId, int $revisionNumber): ?Revision;

    /** Total revision count across all posts. */
    public function countAll(): int;
}
