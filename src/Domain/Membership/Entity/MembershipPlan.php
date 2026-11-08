<?php

declare(strict_types=1);

namespace ValeApp\Domain\Membership\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class MembershipPlan extends BaseEntity
{
    public function __construct(
        private string $name,
        private string $slug,
        private ?string $description,
        private string $price,
        private string $interval,
        private string $gateway,
        private string $tag,
        private int $trialDays,
        private ?string $trialTag,
        private string $trialPrice = '0.00',
        private int $sortOrder = 0,
        private bool $isActive = true,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }
    public function getSlug(): string
    {
        return $this->slug;
    }
    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function getPrice(): string
    {
        return $this->price;
    }
    public function getInterval(): string
    {
        return $this->interval;
    }
    public function getGateway(): string
    {
        return $this->gateway;
    }
    public function getTag(): string
    {
        return $this->tag;
    }
    public function getTrialDays(): int
    {
        return $this->trialDays;
    }
    public function getTrialTag(): ?string
    {
        return $this->trialTag;
    }
    public function getTrialPrice(): string
    {
        return $this->trialPrice;
    }
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }
    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }
    public function setPrice(string $price): void
    {
        $this->price = $price;
    }
    public function setInterval(string $interval): void
    {
        $this->interval = $interval;
    }
    public function setGateway(string $gateway): void
    {
        $this->gateway = $gateway;
    }
    public function setTag(string $tag): void
    {
        $this->tag = $tag;
    }
    public function setTrialDays(int $trialDays): void
    {
        $this->trialDays = $trialDays;
    }
    public function setTrialTag(?string $trialTag): void
    {
        $this->trialTag = $trialTag;
    }
    public function setTrialPrice(string $trialPrice): void
    {
        $this->trialPrice = $trialPrice;
    }
    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }
    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function intervalLabel(): string
    {
        return match ($this->interval) {
            'one_time' => 'One Time',
            'daily'    => 'Per Day',
            'weekly'   => 'Per Week',
            'monthly'  => 'Per Month',
            'yearly'   => 'Per Year',
            default    => ucfirst($this->interval),
        };
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $plan = new self(
            (string) $row['name'],
            (string) $row['slug'],
            $row['description'] !== null ? (string) $row['description'] : null,
            (string) $row['price'],
            (string) $row['plan_interval'],
            (string) $row['gateway'],
            (string) $row['tag'],
            (int) ($row['trial_days'] ?? 0),
            $row['trial_tag'] !== null ? (string) $row['trial_tag'] : null,
            (string) ($row['trial_price'] ?? '0.00'),
            (int) ($row['sort_order'] ?? 0),
            (bool) ($row['is_active'] ?? true),
        );
        $plan->id = (int) $row['id'];
        if (!empty($row['created_at'])) {
            $plan->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $plan->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }
        return $plan;
    }
}
