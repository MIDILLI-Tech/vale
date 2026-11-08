<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;
use ValeApp\Domain\Shared\ValueObject\Slug;

class ProductCategory extends BaseEntity
{
    /** Set by CategoryController::buildTree() — 0 = root, 1 = child, … */
    public int $depth = 0;

    private ?int $parentId = null;
    private string $name;
    private string $language                 = 'en';
    private ?int $translationOf              = null;
    private bool $translatedInTargetLanguage = false;
    /** @var string[] */
    private array $translatedLanguages = [];
    private Slug $slug;
    private ?string $description = null;
    private ?int $imageId        = null;
    private int $sortOrder       = 0;

    public function __construct(string $name, Slug $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }
    public function getName(): string
    {
        return $this->name;
    }

    public function isTranslatedInTargetLanguage(): bool
    {
        return $this->translatedInTargetLanguage;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getTranslationOf(): ?int
    {
        return $this->translationOf;
    }
    public function getSlug(): Slug
    {
        return $this->slug;
    }
    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function getImageId(): ?int
    {
        return $this->imageId;
    }
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setParentId(?int $id): void
    {
        $this->parentId = $id;
    }
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setTranslatedInTargetLanguage(bool $flag): void
    {
        $this->translatedInTargetLanguage = $flag;
    }

    /** @return string[] */
    public function getTranslatedLanguages(): array
    {
        return $this->translatedLanguages;
    }

    /** @param string[] $languages */
    public function setTranslatedLanguages(array $languages): void
    {
        $this->translatedLanguages = array_values(array_filter(array_map('strval', $languages)));
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function setTranslationOf(?int $id): void
    {
        $this->translationOf = $id;
    }
    public function setSlug(Slug $slug): void
    {
        $this->slug = $slug;
    }
    public function setDescription(?string $d): void
    {
        $this->description = $d;
    }
    public function setImageId(?int $id): void
    {
        $this->imageId = $id;
    }
    public function setSortOrder(int $order): void
    {
        $this->sortOrder = $order;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $cat                = new self($row['name'], new Slug($row['slug']));
        $cat->id            = (int) $row['id'];
        $cat->language      = $row['language']     ?? 'en';
        $cat->translationOf = isset($row['translation_of']) ? (int) $row['translation_of'] : null;
        $cat->parentId      = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
        $cat->description   = $row['description'] ?? null;
        $cat->imageId       = isset($row['image_id']) ? (int) $row['image_id'] : null;
        $cat->sortOrder     = (int) ($row['sort_order'] ?? 0);
        if (!empty($row['created_at'])) {
            $cat->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (isset($row['is_translated_in_target'])) {
            $cat->setTranslatedInTargetLanguage((bool) $row['is_translated_in_target']);
        }
        return $cat;
    }
}
