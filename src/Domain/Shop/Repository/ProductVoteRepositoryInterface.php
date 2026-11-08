<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Repository;

use ValeApp\Domain\Shop\Entity\ProductVote;

interface ProductVoteRepositoryInterface
{
    public function findByProductAndSession(int $productId, string $sessionId): ?ProductVote;

    public function save(ProductVote $vote): ProductVote;

    public function findByProductAndUser(int $productId, int $userId): ?ProductVote;

    public function findByCommentId(int $commentId): ?ProductVote;

    /** @return ProductVote[] */
    public function findByProduct(int $productId): array;

    /**
     * @param int[] $productIds
     * @return ProductVote[]
     */
    public function findByProductIds(array $productIds): array;

    /**
     * @return array{average: float, count: int}
     */
    public function getSummary(int $productId): array;

    /**
     * Batch fetch vote summaries for multiple products.
     *
     * @param int[] $productIds
     * @return array<int, array{average: float, count: int}> key = product_id
     */
    public function getSummaries(array $productIds): array;

    public function delete(int $id): void;
}
