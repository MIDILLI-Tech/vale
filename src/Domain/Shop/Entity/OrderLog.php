<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Entity;

class OrderLog
{
    private ?int $id = null;

    public function __construct(
        private int $orderId,
        private string $event,
        private ?string $note = null,
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        private string $type = 'system',
        private ?int $userId = null,
        private ?string $addedBy = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getOrderId(): int
    {
        return $this->orderId;
    }
    public function getEvent(): string
    {
        return $this->event;
    }
    public function getNote(): ?string
    {
        return $this->note;
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function getType(): string
    {
        return $this->type;
    }
    public function getUserId(): ?int
    {
        return $this->userId;
    }
    public function getAddedBy(): ?string
    {
        return $this->addedBy;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $log = new self(
            (int) $row['order_id'],
            $row['event'],
            $row['note'] ?? null,
            !empty($row['created_at']) ? new \DateTimeImmutable($row['created_at']) : new \DateTimeImmutable(),
            $row['type'] ?? 'system',
            isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            $row['added_by'] ?? null,
        );
        $log->id = (int) $row['id'];
        return $log;
    }
}
