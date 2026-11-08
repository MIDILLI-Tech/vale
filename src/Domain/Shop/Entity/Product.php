<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;
use ValeApp\Domain\Shared\ValueObject\Slug;

class Product extends BaseEntity
{
    private string $name;
    private string $language                 = 'en';
    private ?int $translationOf              = null;
    private bool $translatedInTargetLanguage = false;
    private array $translatedLanguages       = [];
    private Slug $slug;
    private ?string $sku                        = null;
    private ?string $description                = null;
    private ?string $shortDescription           = null;
    private float $price                        = 0.0;
    private ?float $salePrice                   = null;
    private ?\DateTimeImmutable $salePriceStart = null;
    private ?\DateTimeImmutable $salePriceEnd   = null;
    private int $stockQuantity                  = 0;
    private string $stockStatus                 = 'in_stock'; // in_stock|out_of_stock|backorder
    private ?float $weight                      = null;
    private ?string $dimensions                 = null;
    private ?int $featuredImageId               = null;
    private string $status                      = 'draft'; // draft|published|unlisted|scheduled|trash
    private ?\DateTimeImmutable $publishedAt    = null;
    private ?\DateTimeImmutable $expiresAt      = null;
    private string $commentStatus               = 'use_global'; // use_global|enabled|disabled
    private string $voteStatus                  = 'use_global'; // use_global|enabled|disabled
    private string $type                        = 'simple'; // simple|variable|digital
    private string $shippingClass               = 'use_global'; // use_global|free|custom
    private ?float $shippingCost                = null;
    private ?int $shippingTimeMin               = null; // estimated delivery days
    private ?int $shippingTimeMax               = null;
    private string $vatMode                     = 'use_global'; // use_global|custom
    private ?float $vatRate                     = null;
    private string $refundMode                  = 'use_global'; // use_global|allow|non_refundable
    private string $downloadSource              = 'none'; // none|internal|external
    private ?int $downloadFileId                = null;
    private ?string $downloadUrl                = null;
    private bool $downloadUrlProxy              = true;
    private int $downloadLimit                  = 0; // 0 = unlimited
    private int $downloadExpiryDays             = 0; // 0 = no expiry
    private ?string $metaTitle                  = null;
    private ?string $metaDescription            = null;
    private int $totalSold                      = 0;
    private array $shippingCountryOverrides     = [];
    private array $vatCountryOverrides          = [];

    // ── Google Merchant Center fields ──────────────────────
    private ?string $gtin                  = null;
    private ?string $mpn                   = null;
    private ?string $brand                 = null;
    private ?string $googleProductCategory = null;
    private string $condition              = 'new';
    private ?string $gender                = null;
    private ?string $ageGroup              = null;
    private ?string $color                 = null;
    private ?string $size                  = null;
    private ?string $material              = null;
    private ?string $pattern               = null;
    private ?string $customLabel0          = null;
    private ?string $customLabel1          = null;
    private ?string $customLabel2          = null;
    private ?string $customLabel3          = null;
    private ?string $customLabel4          = null;
    private bool $gmcExclude               = false;

    /** @var int[] */
    private array $upsellIds = [];

    /** @var int[] */
    private array $crossSellIds = [];

    private ?float $length = null;
    private ?float $width  = null;
    private ?float $height = null;

    /** @var int[] */
    private array $categoryIds = [];

    /** @var string[] */
    private array $accessTags = [];
    public function __construct(string $name, Slug $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
    }

    // ── Getters ──────────────────────────────────────────

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
    public function getSku(): ?string
    {
        return $this->sku;
    }
    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }
    public function getPrice(): float
    {
        return $this->price;
    }
    public function getSalePrice(): ?float
    {
        return $this->salePrice;
    }
    public function getEffectivePrice(): float
    {
        return $this->isSaleActive() ? $this->salePrice : $this->price;
    }
    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }
    public function getStockStatus(): string
    {
        return $this->stockStatus;
    }
    public function isInStock(): bool
    {
        return $this->stockStatus === 'in_stock' || $this->stockStatus === 'on_backorder';
    }
    public function isStockTracked(): bool
    {
        return $this->stockStatus === 'in_stock' && $this->stockQuantity > 0;
    }
    public function getTotalSold(): int
    {
        return $this->totalSold;
    }
    public function getWeight(): ?float
    {
        return $this->weight;
    }
    public function getDimensions(): ?string
    {
        return $this->dimensions;
    }
    public function getFeaturedImageId(): ?int
    {
        return $this->featuredImageId;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }
    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }
    public function getCommentStatus(): string
    {
        return $this->commentStatus;
    }
    public function getVoteStatus(): string
    {
        return $this->voteStatus;
    }
    public function isVoteEnabled(): bool
    {
        return $this->voteStatus === 'enabled';
    }
    public function getType(): string
    {
        return $this->type;
    }
    public function getShippingClass(): string
    {
        return $this->shippingClass;
    }
    public function getShippingCost(): ?float
    {
        return $this->shippingCost;
    }
    public function getShippingTimeMin(): ?int
    {
        return $this->shippingTimeMin;
    }
    public function getShippingTimeMax(): ?int
    {
        return $this->shippingTimeMax;
    }
    public function getVatMode(): string
    {
        return $this->vatMode;
    }
    public function getVatRate(): ?float
    {
        return $this->vatRate;
    }
    public function getRefundMode(): string
    {
        return $this->refundMode;
    }
    public function getDownloadSource(): string
    {
        return $this->downloadSource;
    }
    public function getDownloadFileId(): ?int
    {
        return $this->downloadFileId;
    }
    public function getDownloadUrl(): ?string
    {
        return $this->downloadUrl;
    }
    public function isDownloadUrlProxy(): bool
    {
        return $this->downloadUrlProxy;
    }
    public function getDownloadLimit(): int
    {
        return $this->downloadLimit;
    }
    public function getDownloadExpiryDays(): int
    {
        return $this->downloadExpiryDays;
    }
    public function isDigital(): bool
    {
        return $this->shippingClass === 'digital';
    }
    public function getShippingCountryOverrides(): array
    {
        return $this->shippingCountryOverrides;
    }
    public function getVatCountryOverrides(): array
    {
        return $this->vatCountryOverrides;
    }
    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }
    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    // ── GMC Getters ────────────────────────────────────────
    public function getGtin(): ?string
    {
        return $this->gtin;
    }
    public function getMpn(): ?string
    {
        return $this->mpn;
    }
    public function getBrand(): ?string
    {
        return $this->brand;
    }
    public function getGoogleProductCategory(): ?string
    {
        return $this->googleProductCategory;
    }
    public function getCondition(): string
    {
        return $this->condition;
    }
    public function getGender(): ?string
    {
        return $this->gender;
    }
    public function getAgeGroup(): ?string
    {
        return $this->ageGroup;
    }
    public function getColor(): ?string
    {
        return $this->color;
    }
    public function getSize(): ?string
    {
        return $this->size;
    }
    public function getMaterial(): ?string
    {
        return $this->material;
    }
    public function getPattern(): ?string
    {
        return $this->pattern;
    }
    public function getCustomLabel0(): ?string
    {
        return $this->customLabel0;
    }
    public function getCustomLabel1(): ?string
    {
        return $this->customLabel1;
    }
    public function getCustomLabel2(): ?string
    {
        return $this->customLabel2;
    }
    public function getCustomLabel3(): ?string
    {
        return $this->customLabel3;
    }
    public function getCustomLabel4(): ?string
    {
        return $this->customLabel4;
    }
    public function isGmcExclude(): bool
    {
        return $this->gmcExclude;
    }

    /** @return int[] */
    public function getUpsellIds(): array
    {
        return $this->upsellIds;
    }

    /** @return int[] */
    public function getCrossSellIds(): array
    {
        return $this->crossSellIds;
    }

    public function getLength(): ?float
    {
        return $this->length;
    }

    public function getWidth(): ?float
    {
        return $this->width;
    }

    public function getHeight(): ?float
    {
        return $this->height;
    }

    /** @return int[] */
    public function getCategoryIds(): array
    {
        return $this->categoryIds;
    }

    /** @return string[] */
    public function getAccessTags(): array
    {
        return $this->accessTags;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
    public function isUnlisted(): bool
    {
        return $this->status === 'unlisted';
    }
    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }
    public function isCommentsOpen(): bool
    {
        return $this->commentStatus !== 'disabled';
    }

    // ── Setters ──────────────────────────────────────────

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
    public function setSku(?string $sku): void
    {
        $this->sku = $sku;
    }
    public function setDescription(?string $d): void
    {
        $this->description = $d;
    }
    public function setShortDescription(?string $d): void
    {
        $this->shortDescription = $d;
    }
    public function setPrice(float $price): void
    {
        $this->price = $price;
    }
    public function setSalePrice(?float $price): void
    {
        $this->salePrice = $price;
    }
    public function getSalePriceStart(): ?\DateTimeImmutable
    {
        return $this->salePriceStart;
    }
    public function setSalePriceStart(?\DateTimeImmutable $dt): void
    {
        $this->salePriceStart = $dt;
    }
    public function getSalePriceEnd(): ?\DateTimeImmutable
    {
        return $this->salePriceEnd;
    }
    public function setSalePriceEnd(?\DateTimeImmutable $dt): void
    {
        $this->salePriceEnd = $dt;
    }
    public function isSaleActive(?\DateTimeImmutable $now = null): bool
    {
        if ($this->salePrice === null) {
            return false;
        }
        $now ??= new \DateTimeImmutable();
        if ($this->salePriceStart !== null && $now < $this->salePriceStart) {
            return false;
        }
        if ($this->salePriceEnd !== null && $now >= $this->salePriceEnd) {
            return false;
        }
        return true;
    }
    public function setStockQuantity(int $qty): void
    {
        $this->stockQuantity = $qty;
    }
    public function setStockStatus(string $status): void
    {
        $this->stockStatus = $status;
    }
    public function setWeight(?float $w): void
    {
        $this->weight = $w;
    }
    public function setDimensions(?string $d): void
    {
        $this->dimensions = $d;
    }
    public function setFeaturedImageId(?int $id): void
    {
        $this->featuredImageId = $id;
    }
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }
    public function setCommentStatus(string $status): void
    {
        $this->commentStatus = $status;
    }
    public function setVoteStatus(string $status): void
    {
        $this->voteStatus = $status;
    }
    public function setType(string $type): void
    {
        $this->type = $type;
    }
    public function setShippingClass(string $class): void
    {
        $this->shippingClass = $class;
    }
    public function setShippingCost(?float $cost): void
    {
        $this->shippingCost = $cost;
    }
    public function setShippingTimeMin(?int $days): void
    {
        $this->shippingTimeMin = $days;
    }
    public function setShippingTimeMax(?int $days): void
    {
        $this->shippingTimeMax = $days;
    }
    public function setVatMode(string $mode): void
    {
        $this->vatMode = $mode;
    }
    public function setVatRate(?float $rate): void
    {
        $this->vatRate = $rate;
    }
    public function setRefundMode(string $mode): void
    {
        $this->refundMode = $mode;
    }
    public function setDownloadSource(string $src): void
    {
        $this->downloadSource = $src;
    }
    public function setDownloadFileId(?int $id): void
    {
        $this->downloadFileId = $id;
    }
    public function setDownloadUrl(?string $url): void
    {
        $this->downloadUrl = $url;
    }
    public function setDownloadUrlProxy(bool $proxy): void
    {
        $this->downloadUrlProxy = $proxy;
    }
    public function setDownloadLimit(int $limit): void
    {
        $this->downloadLimit = $limit;
    }
    public function setDownloadExpiryDays(int $days): void
    {
        $this->downloadExpiryDays = $days;
    }
    public function setShippingCountryOverrides(array $overrides): void
    {
        $this->shippingCountryOverrides = $overrides;
    }
    public function setVatCountryOverrides(array $overrides): void
    {
        $this->vatCountryOverrides = $overrides;
    }
    public function setMetaTitle(?string $title): void
    {
        $this->metaTitle = $title;
    }
    public function setMetaDescription(?string $desc): void
    {
        $this->metaDescription = $desc;
    }
    public function setTotalSold(int $count): void
    {
        $this->totalSold = $count;
    }

    // ── GMC Setters ────────────────────────────────────────
    public function setGtin(?string $v): void
    {
        $this->gtin = $v;
    }
    public function setMpn(?string $v): void
    {
        $this->mpn = $v;
    }
    public function setBrand(?string $v): void
    {
        $this->brand = $v;
    }
    public function setGoogleProductCategory(?string $v): void
    {
        $this->googleProductCategory = $v;
    }
    public function setCondition(string $v): void
    {
        $this->condition = $v;
    }
    public function setGender(?string $v): void
    {
        $this->gender = $v;
    }
    public function setAgeGroup(?string $v): void
    {
        $this->ageGroup = $v;
    }
    public function setColor(?string $v): void
    {
        $this->color = $v;
    }
    public function setSize(?string $v): void
    {
        $this->size = $v;
    }
    public function setMaterial(?string $v): void
    {
        $this->material = $v;
    }
    public function setPattern(?string $v): void
    {
        $this->pattern = $v;
    }
    public function setCustomLabel0(?string $v): void
    {
        $this->customLabel0 = $v;
    }
    public function setCustomLabel1(?string $v): void
    {
        $this->customLabel1 = $v;
    }
    public function setCustomLabel2(?string $v): void
    {
        $this->customLabel2 = $v;
    }
    public function setCustomLabel3(?string $v): void
    {
        $this->customLabel3 = $v;
    }
    public function setCustomLabel4(?string $v): void
    {
        $this->customLabel4 = $v;
    }
    public function setGmcExclude(bool $v): void
    {
        $this->gmcExclude = $v;
    }

    /** @param int[] $ids */
    public function setUpsellIds(array $ids): void
    {
        $this->upsellIds = $ids;
    }

    /** @param int[] $ids */
    public function setCrossSellIds(array $ids): void
    {
        $this->crossSellIds = $ids;
    }

    public function setLength(?float $v): void
    {
        $this->length = $v;
    }

    public function setWidth(?float $v): void
    {
        $this->width = $v;
    }

    public function setHeight(?float $v): void
    {
        $this->height = $v;
    }

    /** @param int[] $ids */
    public function setCategoryIds(array $ids): void
    {
        $this->categoryIds = $ids;
    }

    /** @param string[] $tags */
    public function setAccessTags(array $tags): void
    {
        $this->accessTags = array_values(array_filter(array_map('strval', $tags)));
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
    public function schedule(\DateTimeImmutable $at): void
    {
        $this->status      = 'scheduled';
        $this->publishedAt = $at;
    }
    public function setPublishedAt(?\DateTimeImmutable $at): void
    {
        $this->publishedAt = $at;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, array $categoryIds = []): self
    {
        $product = new self(
            $row['name'],
            new Slug($row['slug']),
        );

        $product->id                 = (int) $row['id'];
        $product->language           = $row['language']     ?? 'en';
        $product->translationOf      = isset($row['translation_of']) ? (int) $row['translation_of'] : null;
        $product->sku                = $row['sku']               ?? null;
        $product->description        = $row['description']       ?? null;
        $product->shortDescription   = $row['short_description'] ?? null;
        $product->price              = (float) ($row['price'] ?? 0);
        $product->salePrice          = isset($row['sale_price']) && $row['sale_price'] !== null ? (float) $row['sale_price'] : null;
        $product->salePriceStart     = !empty($row['sale_price_start']) ? new \DateTimeImmutable($row['sale_price_start']) : null;
        $product->salePriceEnd       = !empty($row['sale_price_end']) ? new \DateTimeImmutable($row['sale_price_end']) : null;
        $product->stockQuantity      = (int) ($row['stock_quantity'] ?? 0);
        $product->stockStatus        = $row['stock_status'] ?? 'in_stock';
        $product->weight             = isset($row['weight']) ? (float) $row['weight'] : null;
        $product->dimensions         = $row['dimensions'] ?? null;
        $product->featuredImageId    = isset($row['featured_image_id']) ? (int) $row['featured_image_id'] : null;
        $product->status             = $row['status'] ?? 'draft';
        $product->publishedAt        = !empty($row['published_at']) ? new \DateTimeImmutable($row['published_at']) : null;
        $product->expiresAt          = !empty($row['expires_at']) ? new \DateTimeImmutable($row['expires_at']) : null;
        $product->commentStatus      = $row['comment_status'] ?? 'use_global';
        $product->voteStatus         = $row['vote_status']    ?? 'use_global';
        $product->type               = $row['type']           ?? 'simple';
        $product->shippingClass      = $row['shipping_class'] ?? 'use_global';
        $product->shippingCost       = isset($row['shipping_cost'])     && $row['shipping_cost']     !== null ? (float) $row['shipping_cost'] : null;
        $product->shippingTimeMin    = isset($row['shipping_time_min']) && $row['shipping_time_min'] !== null ? (int) $row['shipping_time_min'] : null;
        $product->shippingTimeMax    = isset($row['shipping_time_max']) && $row['shipping_time_max'] !== null ? (int) $row['shipping_time_max'] : null;
        $product->vatMode            = $row['vat_mode'] ?? 'use_global';
        $product->vatRate            = isset($row['vat_rate']) && $row['vat_rate'] !== null ? (float) $row['vat_rate'] : null;
        $product->refundMode         = $row['refund_mode']     ?? 'use_global';
        $product->downloadSource     = $row['download_source'] ?? 'none';
        $product->downloadFileId     = isset($row['download_file_id']) && $row['download_file_id'] !== null ? (int) $row['download_file_id'] : null;
        $product->downloadUrl        = $row['download_url'] ?? null;
        $product->downloadUrlProxy   = (bool) ($row['download_url_proxy'] ?? true);
        $product->downloadLimit      = (int) ($row['download_limit'] ?? 0);
        $product->downloadExpiryDays = (int) ($row['download_expiry_days'] ?? 0);
        $product->metaTitle          = $row['meta_title']       ?? null;
        $product->metaDescription    = $row['meta_description'] ?? null;
        $product->totalSold          = (int) ($row['total_sold'] ?? 0);

        $product->gtin                     = $row['gtin']                    ?? null;
        $product->mpn                      = $row['mpn']                     ?? null;
        $product->brand                    = $row['brand']                   ?? null;
        $product->googleProductCategory    = $row['google_product_category'] ?? null;
        $product->condition                = $row['condition']               ?? 'new';
        $product->gender                   = $row['gender']                  ?? null;
        $product->ageGroup                 = $row['age_group']               ?? null;
        $product->color                    = $row['color']                   ?? null;
        $product->size                     = $row['size']                    ?? null;
        $product->material                 = $row['material']                ?? null;
        $product->pattern                  = $row['pattern']                 ?? null;
        $product->customLabel0             = $row['custom_label_0']          ?? null;
        $product->customLabel1             = $row['custom_label_1']          ?? null;
        $product->customLabel2             = $row['custom_label_2']          ?? null;
        $product->customLabel3             = $row['custom_label_3']          ?? null;
        $product->customLabel4             = $row['custom_label_4']          ?? null;
        $product->gmcExclude               = (bool) ($row['gmc_exclude'] ?? false);
        $product->upsellIds                = isset($row['upsell_ids']) && is_string($row['upsell_ids'])
            ? (json_decode($row['upsell_ids'], true) ?: [])
            : [];
        $product->crossSellIds             = isset($row['cross_sell_ids']) && is_string($row['cross_sell_ids'])
            ? (json_decode($row['cross_sell_ids'], true) ?: [])
            : [];
        $product->length                   = isset($row['length'])                     && $row['length'] !== null ? (float) $row['length'] : null;
        $product->width                    = isset($row['width'])                      && $row['width']  !== null ? (float) $row['width'] : null;
        $product->height                   = isset($row['height'])                     && $row['height'] !== null ? (float) $row['height'] : null;
        $product->shippingCountryOverrides = isset($row['shipping_country_overrides']) && is_string($row['shipping_country_overrides'])
            ? (json_decode($row['shipping_country_overrides'], true) ?: [])
            : [];
        $product->vatCountryOverrides = isset($row['vat_country_overrides']) && is_string($row['vat_country_overrides'])
            ? (json_decode($row['vat_country_overrides'], true) ?: [])
            : [];

        $product->categoryIds      = $categoryIds;

        $product->accessTags = isset($row['access_tags']) && is_string($row['access_tags'])
            ? (json_decode($row['access_tags'], true) ?: [])
            : (!empty($row['access_tags']) && is_array($row['access_tags']) ? $row['access_tags'] : []);
        if (!empty($row['created_at'])) {
            $product->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $product->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }
        if (isset($row['is_translated_in_target'])) {
            $product->setTranslatedInTargetLanguage((bool) $row['is_translated_in_target']);
        }
        if (isset($row['translated_languages'])) {
            $product->setTranslatedLanguages(
                $row['translated_languages'] === null || $row['translated_languages'] === ''
                    ? []
                    : explode(',', (string) $row['translated_languages'])
            );
        }

        return $product;
    }
}
