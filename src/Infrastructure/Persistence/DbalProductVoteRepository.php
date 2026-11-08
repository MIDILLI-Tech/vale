<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shop\Entity\ProductVote;
use ValeApp\Domain\Shop\Repository\ProductVoteRepositoryInterface;

final class DbalProductVoteRepository implements ProductVoteRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findByProductAndSession(int $productId, string $sessionId): ?ProductVote
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'product_votes' . ' WHERE product_id = ? AND session_id = ?',
            [$productId, $sessionId],
            [ParameterType::INTEGER, ParameterType::STRING],
        );
        return $row !== false ? ProductVote::fromRow($row) : null;
    }

    /** @return ProductVote[] */
    public function findByProduct(int $productId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'product_votes' . ' WHERE product_id = ?',
            [$productId],
            [ParameterType::INTEGER],
        );
        return array_map(fn (array $row) => ProductVote::fromRow($row), $rows);
    }

    /** @inheritDoc */
    public function findByProductIds(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows         = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'product_votes' . " WHERE product_id IN ($placeholders)",
            $productIds,
            array_fill(0, count($productIds), ParameterType::INTEGER),
        );
        return array_map(fn (array $row) => ProductVote::fromRow($row), $rows);
    }

    public function findByProductAndUser(int $productId, int $userId): ?ProductVote
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'product_votes' . ' WHERE product_id = ? AND user_id = ?',
            [$productId, $userId],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
        return $row !== false ? ProductVote::fromRow($row) : null;
    }

    public function findByCommentId(int $commentId): ?ProductVote
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'product_votes' . ' WHERE comment_id = ?',
            [$commentId],
            [ParameterType::INTEGER],
        );
        return $row !== false ? ProductVote::fromRow($row) : null;
    }

    public function save(ProductVote $vote): ProductVote
    {
        $data = [
            'product_id' => $vote->getProductId(),
            'comment_id' => $vote->getCommentId(),
            'session_id' => $vote->getSessionId(),
            'user_id'    => $vote->getUserId(),
            'rating'     => $vote->getRating(),
            'review'     => $vote->getReview(),
        ];

        if ($vote->getId() === null) {
            // Check if a vote already exists for this comment (if provided) or session/product
            $existing = null;
            if ($vote->getCommentId()) {
                $existing = $this->findByCommentId($vote->getCommentId());
            }

            if ($existing === null) {
                $existing = $this->findByProductAndSession($vote->getProductId(), $vote->getSessionId());
            }

            if ($existing !== null) {
                $vote->setId($existing->getId());
            }
        }

        if ($vote->getId() === null) {
            $data['created_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->insert(VL_DB_PREFIX . 'product_votes', $data);
            $vote->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'product_votes', $data, ['id' => $vote->getId()]);
        }

        return $vote;
    }

    /** @return array{average: float, count: int} */
    public function getSummary(int $productId): array
    {
        $result = $this->getSummaries([$productId]);
        return $result[$productId] ?? ['average' => 0.0, 'count' => 0];
    }

    /** @inheritDoc */
    public function getSummaries(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        // Batch resolve translation_of -> original product IDs
        $translationRows = $this->db->fetchAllAssociative(
            'SELECT id, COALESCE(translation_of, id) AS original_id'
            . ' FROM ' . VL_DB_PREFIX . 'products'
            . " WHERE id IN ($placeholders)",
            $productIds,
            array_fill(0, count($productIds), ParameterType::INTEGER),
        );

        $idToOriginal = [];
        $originalIds  = [];
        foreach ($translationRows as $row) {
            $pid                      = (int) $row['id'];
            $originalId               = (int) $row['original_id'];
            $idToOriginal[$pid]       = $originalId;
            $originalIds[$originalId] = true;
        }

        if (empty($originalIds)) {
            return array_fill_keys($productIds, ['average' => 0.0, 'count' => 0]);
        }

        $origPlaceholders = implode(',', array_fill(0, count($originalIds), '?'));
        $origIds          = array_keys($originalIds);

        // Fetch vote summaries for all original product IDs in one query
        $rows = $this->db->fetchAllAssociative(
            'SELECT COALESCE(p.translation_of, p.id) AS group_id, AVG(v.rating) AS average, COUNT(*) AS count'
            . ' FROM ' . VL_DB_PREFIX . 'product_votes v'
            . ' INNER JOIN ' . VL_DB_PREFIX . 'products p ON p.id = v.product_id'
            . " WHERE p.id IN ($origPlaceholders) OR p.translation_of IN ($origPlaceholders)"
            . ' GROUP BY group_id',
            array_merge($origIds, $origIds),
            array_merge(
                array_fill(0, count($origIds), ParameterType::INTEGER),
                array_fill(0, count($origIds), ParameterType::INTEGER),
            ),
        );

        $map = [];
        foreach ($rows as $row) {
            $groupId       = (int) $row['group_id'];
            $avg           = round((float) $row['average'], 1);
            $count         = (int) $row['count'];
            $map[$groupId] = ['average' => $avg, 'count' => $count];
        }

        // Map back to every requested product ID via the original_id lookup
        $result = [];
        foreach ($productIds as $pid) {
            $originalId   = $idToOriginal[$pid] ?? $pid;
            $result[$pid] = $map[$originalId]   ?? ['average' => 0.0, 'count' => 0];
        }

        return $result;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'product_votes', ['id' => $id]);
    }
}
