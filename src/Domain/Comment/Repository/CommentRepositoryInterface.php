<?php

declare(strict_types=1);

namespace ValeApp\Domain\Comment\Repository;

use ValeApp\Domain\Comment\Entity\Comment;
use ValeApp\Domain\Shared\ValueObject\Pagination;

interface CommentRepositoryInterface
{
    public function findById(int $id): ?Comment;

    /** @return Comment[] */
    public function findByEntity(string $entityType, int $entityId, string $status = 'approved'): array;

    /** @return Comment[] */
    public function findByEntityPaginated(string $entityType, int $entityId, Pagination $pagination, string $status = 'approved'): array;

    /** @return Comment[] */
    public function findTopLevelByEntityPaginated(string $entityType, int $entityId, Pagination $pagination, string $status = 'approved'): array;

    /** @return Comment[] */
    public function findAllChildrenByEntity(string $entityType, int $entityId, string $status = 'approved'): array;

    public function countByEntity(string $entityType, int $entityId, string $status = 'approved'): int;

    public function countTopLevelByEntity(string $entityType, int $entityId, string $status = 'approved'): int;

    /** @return Comment[] */
    public function findAll(Pagination $pagination, array $filters = []): array;

    public function count(array $filters = []): int;

    public function save(Comment $comment): Comment;

    public function delete(int $id): void;

    public function hasChildren(int $parentId): bool;

    public function calculateDepth(int $commentId): int;

    public function findByUserAndEntity(int $userId, string $entityType, int $entityId): ?Comment;

    public function countByStatus(string $status): int;

    public function deleteByStatus(string $status): int;
}
