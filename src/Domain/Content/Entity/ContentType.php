<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;
use ValeApp\Domain\Shared\ValueObject\Slug;

class ContentType extends BaseEntity
{
    private string $name;
    private Slug $slug;
    private ?string $description = null;
    private string $icon         = 'document-text';
    private ?string $routePrefix = null;
    private array $supports      = ['title', 'editor', 'thumbnail', 'excerpt', 'author', 'comments', 'revisions', 'categories', 'tags', 'i18n'];
    private array $fieldSchema   = [];
    private bool $isSystem       = false;
    private bool $isActive       = true;

    public function __construct(string $name, Slug $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): Slug
    {
        return $this->slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getRoutePrefix(): ?string
    {
        return $this->routePrefix;
    }

    public function getPublicBasePath(): string
    {
        return self::buildPublicBasePath($this->routePrefix, (string) $this->slug);
    }

    public static function buildPublicBasePath(?string $routePrefix, string $slug): string
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return '/';
        }

        $namespace = $routePrefix !== null ? trim($routePrefix) : '';
        if ($namespace === '') {
            return '/' . $slug;
        }

        return rtrim('/' . ltrim($namespace, '/'), '/') . '/' . $slug;
    }

    /**
     * Per-content-type category URL prefix override (null = use global default).
     */
    public function getCategoryPrefix(): ?string
    {
        return null;
    }

    /**
     * Per-content-type tag URL prefix override (null = use global default).
     */
    public function getTagPrefix(): ?string
    {
        return null;
    }

    public function getSupports(): array
    {
        return $this->supports;
    }

    public function getFieldSchema(): array
    {
        return $this->fieldSchema;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setSlug(Slug $slug): void
    {
        $this->slug = $slug;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function setIcon(string $icon): void
    {
        $this->icon = $icon;
    }

    public function setRoutePrefix(?string $prefix): void
    {
        $this->routePrefix = $prefix;
    }

    public function setSupports(array $supports): void
    {
        $this->supports = $supports;
    }

    public function setFieldSchema(array $schema): void
    {
        $this->fieldSchema = $schema;
    }

    public function setIsSystem(bool $isSystem): void
    {
        $this->isSystem = $isSystem;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $type              = new self($row['name'], new Slug($row['slug']));
        $type->id          = (int) $row['id'];
        $type->description = $row['description'] ?? null;
        $type->icon        = $row['icon']        ?? 'document-text';
        $type->supports    = !empty($row['supports']) ? (json_decode($row['supports'], true) ?? []) : [];
        $type->fieldSchema = !empty($row['field_schema']) ? (json_decode($row['field_schema'], true) ?? []) : [];
        $type->isSystem    = (bool) ($row['is_system'] ?? false);
        $type->isActive    = (bool) ($row['is_active'] ?? true);
        $type->routePrefix = $row['route_prefix'] ?? null;

        if (!empty($row['created_at'])) {
            $type->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $type->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }

        return $type;
    }
}
