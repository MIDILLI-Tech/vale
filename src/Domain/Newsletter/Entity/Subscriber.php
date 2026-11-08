<?php

declare(strict_types=1);

namespace ValeApp\Domain\Newsletter\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class Subscriber extends BaseEntity
{
    private string $email;
    private ?string $name     = null;
    private string $status    = 'pending'; // pending|active|unsubscribed
    private string $token;
    private ?string $ipAddress                               = null;
    private ?int $sourceFormId                               = null;
    private ?\DateTimeImmutable $subscribedAt                = null;
    private ?\DateTimeImmutable $confirmedAt                 = null;
    private ?\DateTimeImmutable $subscriptionExpiresAt       = null;
    private ?string $subscriptionPlanName                    = null;
    private ?string $subscriptionStatus                      = null;
    private ?int $userId                                     = null;
    private string $tier                                     = 'free';
    /** @var string[] */
    private array $tags = [];

    public function __construct(string $email, string $token)
    {
        $this->email = $email;
        $this->token = $token;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
    public function setEmail(string $email): void
    {
        $this->email = $email;
    }
    public function getName(): ?string
    {
        return $this->name;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getToken(): string
    {
        return $this->token;
    }
    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }
    public function getSourceFormId(): ?int
    {
        return $this->sourceFormId;
    }
    public function getSubscribedAt(): ?\DateTimeImmutable
    {
        return $this->subscribedAt;
    }
    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }
    public function getSubscriptionExpiresAt(): ?\DateTimeImmutable
    {
        return $this->subscriptionExpiresAt;
    }

    public function getSubscriptionPlanName(): ?string
    {
        return $this->subscriptionPlanName;
    }

    public function getSubscriptionStatus(): ?string
    {
        return $this->subscriptionStatus;
    }
    /** @return string[] */
    public function getTags(): array
    {
        return $this->tags;
    }
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function setTier(string $tier): void
    {
        $this->tier = $tier;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
    public function setIpAddress(?string $ip): void
    {
        $this->ipAddress = $ip;
    }
    public function setSourceFormId(?int $formId): void
    {
        $this->sourceFormId = $formId;
    }
    public function setSubscribedAt(?\DateTimeImmutable $at): void
    {
        $this->subscribedAt = $at;
    }
    /** @param string[] $tags */
    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

    public function confirm(): void
    {
        $this->status      = 'active';
        $this->confirmedAt = new \DateTimeImmutable();
    }

    public function unsubscribe(): void
    {
        $this->status = 'unsubscribed';
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, array $tags = []): self
    {
        $sub               = new self($row['email'], $row['token']);
        $sub->id           = (int) $row['id'];
        $sub->name         = $row['name']       ?? null;
        $sub->status       = $row['status']     ?? 'pending';
        $sub->ipAddress    = $row['ip_address'] ?? null;
        $sub->sourceFormId = isset($row['source_form_id']) ? (int) $row['source_form_id'] : null;
        $sub->userId       = isset($row['user_id']) ? (int) $row['user_id'] : null;
        $sub->tier         = $row['tier']       ?? 'free';
        $sub->tags         = $tags;
        if (!empty($row['subscribed_at'])) {
            $sub->subscribedAt = new \DateTimeImmutable($row['subscribed_at']);
        }
        if (!empty($row['confirmed_at'])) {
            $sub->confirmedAt = new \DateTimeImmutable($row['confirmed_at']);
        }
        if (!empty($row['created_at'])) {
            $sub->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['subscription_expires_at'])) {
            $sub->subscriptionExpiresAt = new \DateTimeImmutable($row['subscription_expires_at']);
        }
        $sub->subscriptionPlanName = isset($row['subscription_plan_name']) && $row['subscription_plan_name']    !== ''
            ? (string) $row['subscription_plan_name']
            : null;
        $sub->subscriptionStatus   = isset($row['subscription_status'])    && $row['subscription_status']       !== ''
            ? (string) $row['subscription_status']
            : null;
        return $sub;
    }
}
