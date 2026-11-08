<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class ProductVote extends BaseEntity
{
    private int $productId;
    private ?int $commentId = null;
    private ?int $userId;
    private string $sessionId;
    private int $rating; // 1–5
    private ?string $review = null;

    public function __construct(int $productId, string $sessionId, int $rating, ?int $userId = null)
    {
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5.');
        }
        $this->productId = $productId;
        $this->sessionId = $sessionId;
        $this->rating    = $rating;
        $this->userId    = $userId;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }
    public function getCommentId(): ?int
    {
        return $this->commentId;
    }
    public function getUserId(): ?int
    {
        return $this->userId;
    }
    public function getSessionId(): string
    {
        return $this->sessionId;
    }
    public function getRating(): int
    {
        return $this->rating;
    }
    public function getReview(): ?string
    {
        return $this->review;
    }

    public function setCommentId(?int $commentId): void
    {
        $this->commentId = $commentId;
    }

    public function setRating(int $rating): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5.');
        }
        $this->rating = $rating;
    }

    public function setReview(?string $review): void
    {
        $this->review = $review;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $vote = new self(
            (int) $row['product_id'],
            $row['session_id'],
            (int) $row['rating'],
            isset($row['user_id']) ? (int) $row['user_id'] : null,
        );
        $vote->id        = (int) $row['id'];
        $vote->commentId = isset($row['comment_id']) ? (int) $row['comment_id'] : null;
        $vote->review    = $row['review'] ?? null;
        if (!empty($row['created_at'])) {
            $vote->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        return $vote;
    }
}
