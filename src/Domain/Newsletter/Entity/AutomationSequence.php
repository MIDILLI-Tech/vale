<?php

declare(strict_types=1);

namespace ValeApp\Domain\Newsletter\Entity;

final class AutomationSequence
{
    public const int TRACKING_NONE  = 0;
    public const int TRACKING_OPEN  = 1;
    public const int TRACKING_CLICK = 2;
    public const int TRACKING_ALL   = 3;

    private ?int $id = null;
    private string $name;
    private string $triggerType;
    private ?int $triggerFormId;
    private ?string $triggerTag;
    private string $triggerTagMatch = 'any';
    private string $status;
    private int $trackingEnabled           = self::TRACKING_ALL;
    private ?\DateTimeImmutable $createdAt = null;
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        string $name,
        string $triggerType = 'subscribe',
        ?int $triggerFormId = null,
        ?string $triggerTag = null,
        ?string $triggerTagMatch = null,
        string $status = 'active',
    ) {
        $this->name            = $name;
        $this->triggerType     = $triggerType;
        $this->triggerFormId   = $triggerFormId;
        $this->triggerTag      = $triggerTag;
        $this->triggerTagMatch = $triggerTagMatch ?? 'any';
        $this->status          = $status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getTriggerType(): string
    {
        return $this->triggerType;
    }
    public function getTriggerFormId(): ?int
    {
        return $this->triggerFormId;
    }
    public function getTriggerTag(): ?string
    {
        return $this->triggerTag;
    }
    public function getTriggerTagMatch(): string
    {
        return $this->triggerTagMatch;
    }
    public function getTriggerTags(): array
    {
        if ($this->triggerTag === null || $this->triggerTag === '') {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $this->triggerTag)), fn ($v) => $v !== '');
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getTrackingEnabled(): int
    {
        return $this->trackingEnabled;
    }
    public function setTrackingEnabled(int $mask): void
    {
        $this->trackingEnabled = max(0, min(3, $mask));
    }
    public function hasOpenTracking(): bool
    {
        return (bool)($this->trackingEnabled & self::TRACKING_OPEN);
    }
    public function hasClickTracking(): bool
    {
        return (bool)($this->trackingEnabled & self::TRACKING_CLICK);
    }
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }
    public function setName(string $name): void
    {
        $this->name = $name;
    }
    public function setTriggerFormId(?int $id): void
    {
        $this->triggerFormId = $id;
    }
    public function setTriggerTag(?string $tag): void
    {
        $this->triggerTag = $tag;
    }
    public function setTriggerTagMatch(?string $match): void
    {
        $this->triggerTagMatch = $match ?? 'any';
    }

    public function setTriggerType(string $type): void
    {
        $this->triggerType = $type;
    }
    public function activate(): void
    {
        $this->status = 'active';
    }
    public function pause(): void
    {
        $this->status = 'paused';
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $seq = new self(
            name:            $row['name'],
            triggerType:     $row['trigger_type'] ?? 'subscribe',
            triggerFormId:   isset($row['trigger_form_id']) ? (int) $row['trigger_form_id'] : null,
            triggerTag:      $row['trigger_tag']       ?? null,
            triggerTagMatch: $row['trigger_tag_match'] ?? null,
            status:          $row['status']            ?? 'active',
        );
        $seq->id              = (int) $row['id'];
        $seq->trackingEnabled = (int) ($row['tracking_enabled'] ?? self::TRACKING_ALL);
        if (!empty($row['created_at'])) {
            $seq->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $seq->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }
        return $seq;
    }
}
