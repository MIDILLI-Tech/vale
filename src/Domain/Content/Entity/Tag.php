<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;
use ValeApp\Domain\Shared\ValueObject\Slug;

class Tag extends BaseEntity
{
    private string $name;
    private string $language                 = 'en';
    private ?int $translationOf              = null;
    private bool $translatedInTargetLanguage = false;
    /** @var string[] */
    private array $translatedLanguages = [];
    private Slug $slug;
    private string $contentType = 'post';

    public function __construct(string $name, Slug $slug, string $contentType = 'post')
    {
        $this->name        = $name;
        $this->slug        = $slug;
        $this->contentType = $contentType;
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

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function setContentType(string $contentType): void
    {
        $this->contentType = $contentType;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $tag                = new self($row['name'], new Slug($row['slug']), $row['content_type'] ?? 'post');
        $tag->id            = (int) $row['id'];
        $tag->language      = $row['language']     ?? 'en';
        $tag->translationOf = isset($row['translation_of']) ? (int) $row['translation_of'] : null;
        if (!empty($row['created_at'])) {
            $tag->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (isset($row['is_translated_in_target'])) {
            $tag->setTranslatedInTargetLanguage((bool) $row['is_translated_in_target']);
        }
        return $tag;
    }
}
