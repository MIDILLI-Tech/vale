<?php

declare(strict_types=1);

namespace ValeApp\Domain\Menu\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class MenuItem extends BaseEntity
{
    private int $menuId;
    private ?int $parentId = null;
    private string $type   = 'custom'; // custom, home, blog, post, page, category, tag, shop, shop_category, product
    private string $label;
    private ?string $url      = null;
    private string $target    = '_self';
    private ?int $referenceId = null;
    private int $sortOrder    = 0;
    private ?string $cssClass = null;

    public function __construct(int $menuId, string $label)
    {
        $this->menuId = $menuId;
        $this->label  = $label;
    }

    public function getMenuId(): int
    {
        return $this->menuId;
    }
    public function getParentId(): ?int
    {
        return $this->parentId;
    }
    public function getType(): string
    {
        return $this->type;
    }
    public function getLabel(): string
    {
        return $this->label;
    }
    public function getUrl(): ?string
    {
        return $this->url;
    }
    public function getTarget(): string
    {
        return $this->target;
    }
    public function getReferenceId(): ?int
    {
        return $this->referenceId;
    }
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
    public function getCssClass(): ?string
    {
        return $this->cssClass;
    }

    public function setParentId(?int $id): void
    {
        $this->parentId = $id;
    }
    public function setType(string $type): void
    {
        $this->type = $type;
    }
    public function setLabel(string $label): void
    {
        $this->label = $label;
    }
    public function setUrl(?string $url): void
    {
        $this->url = $url;
    }
    public function setTarget(string $target): void
    {
        $this->target = $target;
    }
    public function setReferenceId(?int $id): void
    {
        $this->referenceId = $id;
    }
    public function setSortOrder(int $order): void
    {
        $this->sortOrder = $order;
    }
    public function setCssClass(?string $css): void
    {
        $this->cssClass = $css;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $mi              = new self((int) $row['menu_id'], $row['label']);
        $mi->id          = (int) $row['id'];
        $mi->parentId    = $row['parent_id'] ? (int) $row['parent_id'] : null;
        $mi->type        = $row['type']   ?? 'custom';
        $mi->url         = $row['url']    ?? null;
        $mi->target      = $row['target'] ?? '_self';
        $mi->referenceId = $row['reference_id'] ? (int) $row['reference_id'] : null;
        $mi->sortOrder   = (int) ($row['sort_order'] ?? 0);
        $mi->cssClass    = $row['css_class'] ?? null;
        if (!empty($row['created_at'])) {
            $mi->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $mi->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }
        return $mi;
    }
}
