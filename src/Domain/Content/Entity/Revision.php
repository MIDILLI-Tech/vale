<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content\Entity;

final class Revision
{
    private ?int $id = null;
    private int $postId;
    private int $authorId;
    private string $title;
    private ?string $body;
    private ?string $excerpt;
    private int $revisionNumber;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        int $postId,
        int $authorId,
        string $title,
        ?string $body,
        ?string $excerpt,
        int $revisionNumber,
    ) {
        $this->postId         = $postId;
        $this->authorId       = $authorId;
        $this->title          = $title;
        $this->body           = $body;
        $this->excerpt        = $excerpt;
        $this->revisionNumber = $revisionNumber;
        $this->createdAt      = new \DateTimeImmutable();
    }

    public static function fromRow(array $row): self
    {
        $rev = new self(
            (int) $row['post_id'],
            (int) $row['author_id'],
            $row['title'],
            $row['body']    ?? null,
            $row['excerpt'] ?? null,
            (int) $row['revision_number'],
        );
        $rev->id        = (int) $row['id'];
        $rev->createdAt = new \DateTimeImmutable($row['created_at'] ?? 'now');
        return $rev;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getPostId(): int
    {
        return $this->postId;
    }
    public function getAuthorId(): int
    {
        return $this->authorId;
    }
    public function getTitle(): string
    {
        return $this->title;
    }
    public function getBody(): ?string
    {
        return $this->body;
    }
    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }
    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
