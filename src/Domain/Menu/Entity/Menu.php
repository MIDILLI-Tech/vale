<?php

declare(strict_types=1);

namespace ValeApp\Domain\Menu\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;
use ValeApp\Domain\Shared\ValueObject\Slug;

class Menu extends BaseEntity
{
    private string $name;
    private string $language                 = 'en';
    private ?int $translationOf              = null;
    private bool $translatedInTargetLanguage = false;
    private Slug $slug;
    private ?string $location = null;

    public function __construct(string $name, Slug $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
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
    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setTranslatedInTargetLanguage(bool $flag): void
    {
        $this->translatedInTargetLanguage = $flag;
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
    public function setLocation(?string $loc): void
    {
        $this->location = $loc;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $m                = new self($row['name'], Slug::fromString($row['slug']));
        $m->id            = (int) $row['id'];
        $m->language      = $row['language']     ?? 'en';
        $m->translationOf = isset($row['translation_of']) ? (int) $row['translation_of'] : null;
        $m->location      = $row['location'] ?? null;
        if (!empty($row['created_at'])) {
            $m->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $m->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }
        if (isset($row['is_translated_in_target'])) {
            $m->setTranslatedInTargetLanguage((bool) $row['is_translated_in_target']);
        }
        return $m;
    }
}
