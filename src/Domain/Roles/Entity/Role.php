<?php

declare(strict_types=1);

namespace ValeApp\Domain\Roles\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class Role extends BaseEntity
{
    private string $slug;
    private string $name;
    private ?string $description = null;
    private bool $isSystem       = false;

    /** @var int[] permission IDs assigned to this role */
    private array $permissionIds = [];

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
    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function isSystem(): bool
    {
        return $this->isSystem;
    }
    /** @return int[] */
    public function getPermissionIds(): array
    {
        return $this->permissionIds;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }
    public function setName(string $name): void
    {
        $this->name = $name;
    }
    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }
    public function setIsSystem(bool $isSystem): void
    {
        $this->isSystem = $isSystem;
    }
    /** @param int[] $ids */
    public function setPermissionIds(array $ids): void
    {
        $this->permissionIds = $ids;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, array $permissionIds = []): self
    {
        $role                = new self($row['slug'], $row['name']);
        $role->id            = (int) $row['id'];
        $role->description   = $row['description'] ?? null;
        $role->isSystem      = (bool) ($row['is_system'] ?? false);
        $role->permissionIds = $permissionIds;
        if (!empty($row['created_at'])) {
            $role->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        return $role;
    }
}
