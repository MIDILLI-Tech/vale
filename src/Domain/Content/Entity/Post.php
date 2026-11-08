<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content\Entity;

use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Shared\Entity\BaseEntity;
use ValeApp\Domain\Shared\ValueObject\Slug;

class Post extends BaseEntity
{
    public const int MAX_PAGE_TEMPLATE_LENGTH = 50;

    private string $contentType              = 'post';
    private string $language                 = 'en';
    private ?int $translationOf              = null;
    private bool $translatedInTargetLanguage = false;
    private array $translatedLanguages       = [];
    private int $authorId;
    private string $title;
    private Slug $slug;
    private ?string $excerpt                 = null;
    private ?string $body                    = null;
    private string $status                   = 'draft';
    private ?int $featuredImageId            = null;
    private ?int $parentId                   = null;
    private int $sortOrder                   = 0;
    private string $commentStatus            = 'use_global';
    private string $pageTemplate             = 'standard';
    private bool $hideTitle                  = false;
    private ?string $metaTitle               = null;
    private ?string $metaDescription         = null;
    private ?\DateTimeImmutable $publishedAt = null;
    private ?\DateTimeImmutable $expiresAt   = null;
    private ?int $swapPostId                 = null;
    private int $viewCount                   = 0;
    private bool $rssExclude                 = false;
    /** @var string[] */
    private array $accessTags = [];

    /** @var int[] */
    private array $categoryIds = [];
    /** @var int[] */
    private array $tagIds = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    public function __construct(string $title, Slug $slug, int $authorId)
    {
        $this->title    = $title;
        $this->slug     = $slug;
        $this->authorId = $authorId;
    }

    // ── Getters ──────────────────────────────────────────

    public function getContentType(): string
    {
        return $this->contentType;
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
    public function getAuthorId(): int
    {
        return $this->authorId;
    }
    public function getTitle(): string
    {
        return $this->title;
    }
    public function getSlug(): Slug
    {
        return $this->slug;
    }
    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }
    public function getBody(): ?string
    {
        return $this->body;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getFeaturedImageId(): ?int
    {
        return $this->featuredImageId;
    }

    public function getThumbnail(?string $uploadsBase = null): ?array
    {
        if ($this->featuredImageId === null) {
            return null;
        }

        // Lazy-load via a static container accessor set in bootstrap if available.
        // This keeps the entity framework-agnostic while still allowing thumbnail resolution.
        $resolver = Post::getThumbnailResolver();
        if ($resolver === null) {
            return null;
        }

        return $resolver($this->featuredImageId, $uploadsBase);
    }

    public static function setThumbnailResolver(?callable $resolver): void
    {
        self::$thumbnailResolver = $resolver;
    }

    /** @var callable|null */
    private static $thumbnailResolver = null;

    private static function getThumbnailResolver(): ?callable
    {
        return self::$thumbnailResolver;
    }
    public function getParentId(): ?int
    {
        return $this->parentId;
    }
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
    public function getCommentStatus(): string
    {
        return $this->commentStatus;
    }
    public function getPageTemplate(): string
    {
        return $this->pageTemplate;
    }
    public function isHideTitle(): bool
    {
        return $this->hideTitle;
    }
    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }
    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }
    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }
    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }
    public function getSwapPostId(): ?int
    {
        return $this->swapPostId;
    }
    public function getViewCount(): int
    {
        return $this->viewCount;
    }
    /** @return int[] */
    public function getCategoryIds(): array
    {
        return $this->categoryIds;
    }
    /** @return int[] */
    public function getTagIds(): array
    {
        return $this->tagIds;
    }
    public function isRssExcluded(): bool
    {
        return $this->rssExclude;
    }

    /** @return string[] */
    public function getAccessTags(): array
    {
        return $this->accessTags;
    }

    /** @param string[] $tags */
    public function setAccessTags(array $tags): void
    {
        $this->accessTags = $tags;
    }

    // ── Setters ──────────────────────────────────────────

    public function setContentType(string $type): void
    {
        $this->contentType = $type;
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
    public function setAuthorId(int $id): void
    {
        $this->authorId = $id;
    }
    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
    public function setSlug(Slug $slug): void
    {
        $this->slug = $slug;
    }
    public function setExcerpt(?string $excerpt): void
    {
        $this->excerpt = $excerpt;
    }
    public function setBody(?string $body): void
    {
        $this->body = $body;
    }
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
    public function setFeaturedImageId(?int $id): void
    {
        $this->featuredImageId = $id;
    }
    public function setParentId(?int $id): void
    {
        $this->parentId = $id;
    }
    public function setSortOrder(int $order): void
    {
        $this->sortOrder = $order;
    }
    public function setCommentStatus(string $status): void
    {
        $this->commentStatus = $status;
    }
    public function setPageTemplate(string $tpl): void
    {
        $this->pageTemplate = $tpl;
    }
    public function setHideTitle(bool $v): void
    {
        $this->hideTitle = $v;
    }
    public function setMetaTitle(?string $title): void
    {
        $this->metaTitle = $title;
    }
    public function setMetaDescription(?string $desc): void
    {
        $this->metaDescription = $desc;
    }
    public function setPublishedAt(?\DateTimeImmutable $at): void
    {
        $this->publishedAt = $at;
    }
    public function setExpiresAt(?\DateTimeImmutable $at): void
    {
        $this->expiresAt = $at;
    }
    public function setSwapPostId(?int $id): void
    {
        $this->swapPostId = $id;
    }
    public function setRssExclude(bool $v): void
    {
        $this->rssExclude = $v;
    }
    /** @param int[] $ids */
    public function setCategoryIds(array $ids): void
    {
        $this->categoryIds = $ids;
    }
    /** @param int[] $ids */
    public function setTagIds(array $ids): void
    {
        $this->tagIds = $ids;
    }

    /** @return array<string, mixed> */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /** @param array<string, mixed> $meta */
    public function setMeta(array $meta): void
    {
        $this->meta = $meta;
    }

    public function getMetaValue(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function setMetaValue(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    // ── Domain logic ─────────────────────────────────────

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isUnlisted(): bool
    {
        return $this->status === 'unlisted';
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    /** Publicly accessible via direct URL but excluded from all listings. */
    public function makeUnlisted(): void
    {
        $this->status = 'unlisted';
        $this->publishedAt ??= new \DateTimeImmutable();
    }

    public function publish(): void
    {
        $this->status = 'published';
        $this->publishedAt ??= new \DateTimeImmutable();
    }

    public function trash(): void
    {
        $this->status = 'trash';
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if (!in_array($this->status, ['published', 'unlisted'], true) || $this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function hasSwap(): bool
    {
        return $this->swapPostId !== null;
    }

    /**
     * A live record expires: swap linkage is consumed (one-shot): the
     * caller performs the partner handover first, then clears the link via
     * this method. Expired records stay out of all public listings until
     * manually re-published.
     */
    public function expire(): void
    {
        $this->status     = 'expired';
        $this->expiresAt  = null;
        $this->swapPostId = null;
    }

    public function isExpiredStatus(): bool
    {
        return $this->status === 'expired';
    }

    /**
     * @param array<string, mixed> $row
     * @param int[] $categoryIds
     * @param int[] $tagIds
     * @param array<string, mixed> $meta
     */
    public static function fromRow(array $row, array $categoryIds = [], array $tagIds = [], array $meta = []): self
    {
        $post = new self(
            $row['title'],
            new Slug($row['slug']),
            (int) $row['author_id'],
        );

        $post->id              = (int) $row['id'];
        $post->contentType     = $row['content_type'] ?? 'post';
        $post->language        = $row['language']     ?? 'en';
        $post->translationOf   = isset($row['translation_of']) ? (int) $row['translation_of'] : null;
        $post->excerpt         = $row['excerpt']      ?? null;
        $post->body            = $row['body']         ?? null;
        $post->status          = $row['status']       ?? 'draft';
        $post->featuredImageId = isset($row['featured_image_id']) ? (int) $row['featured_image_id'] : null;
        $post->parentId        = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
        $post->sortOrder       = (int) ($row['sort_order'] ?? 0);
        $post->commentStatus   = $row['comment_status']   ?? 'use_global';
        $post->pageTemplate    = $row['page_template']    ?? 'standard';
        $post->hideTitle       = (bool) ($row['hide_title'] ?? false);
        $post->metaTitle       = $row['meta_title']       ?? null;
        $post->metaDescription = $row['meta_description'] ?? null;
        $post->rssExclude      = (bool) ($row['rss_exclude'] ?? false);
        $post->viewCount       = (int) ($row['view_count'] ?? 0);
        $post->accessTags      = !empty($row['access_tags']) ? json_decode($row['access_tags'], true) ?? [] : [];
        $post->categoryIds     = $categoryIds;
        $post->tagIds          = $tagIds;
        $post->meta            = $meta;

        if (!empty($row['published_at'])) {
            $post->publishedAt = new \DateTimeImmutable($row['published_at']);
        }
        if (!empty($row['expires_at'])) {
            $post->expiresAt = new \DateTimeImmutable($row['expires_at']);
        }
        $post->swapPostId = isset($row['swap_post_id']) && $row['swap_post_id'] !== null
            ? (int) $row['swap_post_id']
            : null;
        if (!empty($row['created_at'])) {
            $post->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $post->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }
        if (isset($row['is_translated_in_target'])) {
            $post->setTranslatedInTargetLanguage((bool) $row['is_translated_in_target']);
        }
        if (isset($row['translated_languages'])) {
            $post->setTranslatedLanguages(
                $row['translated_languages'] === null || $row['translated_languages'] === ''
                    ? []
                    : explode(',', (string) $row['translated_languages'])
            );
        }

        return $post;
    }
}
