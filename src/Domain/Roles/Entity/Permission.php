<?php

declare(strict_types=1);

namespace ValeApp\Domain\Roles\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class Permission extends BaseEntity
{
    private string $slug;
    private string $name;
    private ?string $groupName = null;

    public function __construct(string $slug, string $name)
    {
        $this->slug = $slug;
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getGroupName(): ?string
    {
        return $this->groupName;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }
    public function setName(string $name): void
    {
        $this->name = $name;
    }
    public function setGroupName(?string $groupName): void
    {
        $this->groupName = $groupName;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $perm            = new self($row['slug'], $row['name']);
        $perm->id        = (int) $row['id'];
        $perm->groupName = $row['group_name'] ?? null;
        if (!empty($row['created_at'])) {
            $perm->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        return $perm;
    }
}
