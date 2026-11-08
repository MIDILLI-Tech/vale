<?php

declare(strict_types=1);

namespace ValeApp\Domain\Form\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class FormSubmission extends BaseEntity
{
    private int $formId;
    private array $data        = [];
    private ?string $ipAddress = null;
    private ?string $userAgent = null;
    private bool $isRead       = false;

    public function __construct(int $formId, array $data)
    {
        $this->formId = $formId;
        $this->data   = $data;
    }

    public function getFormId(): int
    {
        return $this->formId;
    }
    public function getData(): array
    {
        return $this->data;
    }
    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIpAddress(?string $ip): void
    {
        $this->ipAddress = $ip;
    }
    public function setUserAgent(?string $ua): void
    {
        $this->userAgent = $ua;
    }
    public function markRead(): void
    {
        $this->isRead = true;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $submission = new self(
            (int) $row['form_id'],
            !empty($row['data']) ? (json_decode($row['data'], true) ?? []) : [],
        );
        $submission->id        = (int) $row['id'];
        $submission->ipAddress = $row['ip_address'] ?? null;
        $submission->userAgent = $row['user_agent'] ?? null;
        $submission->isRead    = (bool) ($row['is_read'] ?? false);
        if (!empty($row['created_at'])) {
            $submission->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        return $submission;
    }
}
