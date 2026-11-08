<?php

declare(strict_types=1);

namespace ValeApp\Domain\Comment\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class Comment extends BaseEntity
{
    private string $entityType = 'post'; // post|product
    private int $entityId;
    private ?int $parentId = null;
    private string $authorName;
    private string $authorEmail;
    private ?string $authorUrl = null;
    private ?string $authorIp  = null;
    private ?int $userId       = null;
    private string $body;
    private string $status      = 'pending'; // pending|approved|spam|trash
    private bool $verified      = false;
    private bool $notifyReplies = false;
    private string $locale      = 'en';

    public function __construct(
        string $entityType,
        int $entityId,
        string $authorName,
        string $authorEmail,
        string $body,
    ) {
        $this->entityType  = $entityType;
        $this->entityId    = $entityId;
        $this->authorName  = $authorName;
        $this->authorEmail = $authorEmail;
        $this->body        = $body;
    }

    // ── Getters ──────────────────────────────────────────

    public function getEntityType(): string
    {
        return $this->entityType;
    }
    public function getEntityId(): int
    {
        return $this->entityId;
    }
    public function getParentId(): ?int
    {
        return $this->parentId;
    }
    public function getAuthorName(): string
    {
        return $this->authorName;
    }
    public function getAuthorEmail(): string
    {
        return $this->authorEmail;
    }
    public function getAuthorUrl(): ?string
    {
        return $this->authorUrl;
    }
    public function getAuthorIp(): ?string
    {
        return $this->authorIp;
    }
    public function getUserId(): ?int
    {
        return $this->userId;
    }
    public function getBody(): string
    {
        return $this->body;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
    public function isVerified(): bool
    {
        return $this->verified;
    }
    public function setVerified(bool $verified): void
    {
        $this->verified = $verified;
    }

    // ── Setters ──────────────────────────────────────────

    public function setParentId(?int $id): void
    {
        $this->parentId = $id;
    }
    public function setAuthorName(string $name): void
    {
        $this->authorName = $name;
    }
    public function setAuthorEmail(string $email): void
    {
        $this->authorEmail = $email;
    }
    public function setAuthorUrl(?string $url): void
    {
        $this->authorUrl = $url;
    }
    public function setAuthorIp(?string $ip): void
    {
        $this->authorIp = $ip;
    }
    public function setUserId(?int $id): void
    {
        $this->userId = $id;
    }
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function isNotifyReplies(): bool
    {
        return $this->notifyReplies;
    }

    public function setNotifyReplies(bool $notifyReplies): void
    {
        $this->notifyReplies = $notifyReplies;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function approve(): void
    {
        $this->status = 'approved';
    }
    public function spam(): void
    {
        $this->status = 'spam';
    }
    public function trash(): void
    {
        $this->status = 'trash';
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $comment = new self(
            $row['entity_type'] ?? 'post',
            (int) $row['entity_id'],
            $row['author_name'],
            $row['author_email'],
            $row['body'],
        );

        $comment->id            = (int) $row['id'];
        $comment->parentId      = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
        $comment->authorUrl     = $row['author_url'] ?? null;
        $comment->authorIp      = $row['author_ip']  ?? null;
        $comment->userId        = isset($row['user_id']) ? (int) $row['user_id'] : null;
        $comment->status        = $row['status'] ?? 'pending';
        $comment->verified      = !empty($row['verified']);
        $comment->notifyReplies = !empty($row['notify_replies']);
        $comment->locale        = $row['locale'] ?? 'en';

        if (!empty($row['created_at'])) {
            $comment->createdAt = new \DateTimeImmutable($row['created_at']);
        }

        return $comment;
    }
}
