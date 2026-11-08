<?php

declare(strict_types=1);

namespace ValeApp\Application\Seo;

use ValeApp\Domain\Seo\Entity\SeoMeta;

/**
 * SeoState — holds the SEO metadata for the current request.
 * Populated by controllers, consumed by the theme hook.
 */
final class SeoState
{
    private ?SeoMeta $seo        = null;
    private ?object $entity      = null;
    private ?string $archiveType = null;

    public function set(?SeoMeta $seo, ?object $entity = null): void
    {
        $this->seo    = $seo;
        $this->entity = $entity;
    }

    public function getSeo(): ?SeoMeta
    {
        return $this->seo;
    }

    public function getEntity(): ?object
    {
        return $this->entity;
    }

    public function setArchiveType(?string $archiveType): void
    {
        $this->archiveType = $archiveType;
    }

    public function getArchiveType(): ?string
    {
        return $this->archiveType;
    }

    /** @return array<string, string> locale => slug/path */
    public function getTranslations(): array
    {
        return $this->translations;
    }

    /** @return array<string, string> locale => slug/path */
    public function translations(): array
    {
        return $this->translations;
    }

    private array $translations = [];

    public function setTranslations(array $translations): void
    {
        $this->translations = $translations;
    }
}
