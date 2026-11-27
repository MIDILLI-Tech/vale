<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

final class WithdrawalRequest extends BaseEntity
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_COMPLETED = 'completed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
    ];

    private string $orderNumber;
    private string $customerEmail;
    private string $reference;
    private ?int $orderId                    = null;
    private ?string $customerName            = null;
    private string $status                   = self::STATUS_PENDING;
    private string $locale                   = 'en';
    private string $source                   = 'online';
    private ?string $reason                  = null;
    private ?string $itemsNote               = null;
    private ?string $items                   = null;
    private ?string $adminNote               = null;
    private ?string $ipAddress               = null;
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(string $orderNumber, string $customerEmail, string $reference)
    {
        $this->orderNumber   = $orderNumber;
        $this->customerEmail = $customerEmail;
        $this->reference     = $reference;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getCustomerEmail(): string
    {
        return $this->customerEmail;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getItemsNote(): ?string
    {
        return $this->itemsNote;
    }

    /** @return list<array<string, mixed>> */
    public function getItems(): array
    {
        if ($this->items === null || $this->items === '') {
            return [];
        }

        $decoded = json_decode($this->items, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setOrderId(?int $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function setCustomerName(?string $name): void
    {
        $name               = $name !== null ? trim($name) : null;
        $this->customerName = $name === '' ? null : $name;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            $status = self::STATUS_PENDING;
        }
        $this->status = $status;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale !== '' ? $locale : 'en';
    }

    public function setSource(string $source): void
    {
        $this->source = $source !== '' ? $source : 'online';
    }

    public function setReason(?string $reason): void
    {
        $reason       = $reason !== null ? trim($reason) : null;
        $this->reason = $reason === '' ? null : $reason;
    }

    public function setItemsNote(?string $note): void
    {
        $note            = $note !== null ? trim($note) : null;
        $this->itemsNote = $note === '' ? null : $note;
    }

    /** @param list<array<string, mixed>> $items */
    public function setItems(array $items): void
    {
        $this->items = $items === []
            ? null
            : (string) json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function setAdminNote(?string $note): void
    {
        $note            = $note !== null ? trim($note) : null;
        $this->adminNote = $note === '' ? null : $note;
    }

    public function setIpAddress(?string $ip): void
    {
        $this->ipAddress = $ip;
    }

    public function markProcessed(): void
    {
        $this->processedAt = new \DateTimeImmutable();
    }

    public function setProcessedAt(?\DateTimeImmutable $at): void
    {
        $this->processedAt = $at;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $request = new self(
            (string) ($row['order_number'] ?? ''),
            (string) ($row['customer_email'] ?? ''),
            (string) ($row['reference'] ?? ''),
        );

        if (!empty($row['id'])) {
            $request->id = (int) $row['id'];
        }
        $request->orderId       = !empty($row['order_id']) ? (int) $row['order_id'] : null;
        $request->customerName  = $row['customer_name'] ?? null;
        $request->status        = in_array($row['status'] ?? '', self::STATUSES, true)
            ? (string) $row['status']
            : self::STATUS_PENDING;
        $request->locale        = (string) ($row['locale'] ?? 'en');
        $request->source        = (string) ($row['source'] ?? 'online');
        $request->reason        = $row['reason']     ?? null;
        $request->itemsNote     = $row['items_note'] ?? null;
        $request->items         = $row['items']      ?? null;
        $request->adminNote     = $row['admin_note'] ?? null;
        $request->ipAddress     = $row['ip_address'] ?? null;

        if (!empty($row['processed_at'])) {
            $request->processedAt = new \DateTimeImmutable($row['processed_at']);
        }
        if (!empty($row['created_at'])) {
            $request->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $request->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }

        return $request;
    }
}
