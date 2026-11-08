<?php

declare(strict_types=1);

namespace ValeApp\Domain\Seo\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class SeoMeta extends BaseEntity
{
    private string $entityType;
    private int $entityId;
    private ?string $metaTitle       = null;
    private ?string $metaDescription = null;
    private ?string $ogTitle         = null;
    private ?string $ogDescription   = null;
    private ?int $ogImageId          = null;
    private string $twitterCardType  = 'summary';
    private ?string $canonicalUrl    = null;
    private string $robots           = 'index,follow';
    private bool $sitemapExclude     = false;
    private ?string $focusKeyword    = null;

    public function __construct(string $entityType, int $entityId)
    {
        $this->entityType = $entityType;
        $this->entityId   = $entityId;
    }

    // ── Getters ──────────────────────────────────────────
    public function getEntityType(): string
    {
        return $this->entityType;
    }
    public function getEntityId(): int
    {
        return $this->entityId;
    }
    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }
    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }
    public function getOgTitle(): ?string
    {
        return $this->ogTitle;
    }
    public function getOgDescription(): ?string
    {
        return $this->ogDescription;
    }
    public function getOgImageId(): ?int
    {
        return $this->ogImageId;
    }
    public function getTwitterCardType(): string
    {
        return $this->twitterCardType;
    }
    public function getCanonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }
    public function getRobots(): string
    {
        return $this->robots;
    }
    public function isSitemapExcluded(): bool
    {
        return $this->sitemapExclude;
    }
    public function getFocusKeyword(): ?string
    {
        return $this->focusKeyword;
    }

    /**
     * Returns the description optimized for LLMs.
     * Currently shares the same value as Meta Description.
     */
    public function getLlmDescription(): ?string
    {
        return $this->metaDescription;
    }

    // ── Setters ──────────────────────────────────────────
    public function setMetaTitle(?string $v): void
    {
        $this->metaTitle = $v;
    }
    public function setMetaDescription(?string $v): void
    {
        $this->metaDescription = $v;
    }
    public function setOgTitle(?string $v): void
    {
        $this->ogTitle = $v;
    }
    public function setOgDescription(?string $v): void
    {
        $this->ogDescription = $v;
    }
    public function setOgImageId(?int $v): void
    {
        $this->ogImageId = $v;
    }
    public function setTwitterCardType(string $v): void
    {
        $this->twitterCardType = $v;
    }
    public function setCanonicalUrl(?string $v): void
    {
        $this->canonicalUrl = $v;
    }
    public function setRobots(string $v): void
    {
        $this->robots = $v;
    }
    public function setSitemapExcluded(bool $v): void
    {
        $this->sitemapExclude = $v;
    }
    public function setFocusKeyword(?string $v): void
    {
        $this->focusKeyword = $v;
    }

    /**
     * Effective meta title: og_title falls back to meta_title.
     */
    public function getEffectiveOgTitle(): ?string
    {
        return $this->ogTitle ?? $this->metaTitle;
    }

    /**
     * Effective meta description: og_description falls back to meta_description.
     */
    public function getEffectiveOgDescription(): ?string
    {
        return $this->ogDescription ?? $this->metaDescription;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $seo                   = new self($row['entity_type'], (int) $row['entity_id']);
        $seo->id               = (int) $row['id'];
        $seo->metaTitle        = $row['meta_title']       ?? null;
        $seo->metaDescription  = $row['meta_description'] ?? null;
        $seo->ogTitle          = $row['og_title']         ?? null;
        $seo->ogDescription    = $row['og_description']   ?? null;
        $seo->ogImageId        = isset($row['og_image_id']) ? (int) $row['og_image_id'] : null;
        $seo->twitterCardType  = $row['twitter_card_type'] ?? 'summary';
        $seo->canonicalUrl     = $row['canonical_url']     ?? null;
        $seo->robots           = $row['robots']            ?? 'index,follow';
        $seo->sitemapExclude   = isset($row['sitemap_exclude']) ? (bool) $row['sitemap_exclude'] : str_contains((string) ($row['robots'] ?? ''), 'noindex');
        $seo->focusKeyword     = $row['focus_keyword']     ?? null;

        if (!empty($row['created_at'])) {
            $seo->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $seo->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }

        return $seo;
    }
}
