<?php

declare(strict_types=1);

namespace ValeApp\Domain\Media\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class Media extends BaseEntity
{
    private int $userId;
    private string $filename;
    private string $originalName;
    private string $mimeType;
    private int $size;
    private string $path;
    private string $disk         = 'local';
    private ?string $altText     = null;
    private ?string $title       = null;
    private ?string $description = null;
    /** @var array<string, string> */
    private array $altTextI18n = [];
    /** @var array<string, string> */
    private array $descriptionI18n = [];
    /** @var array<string, string> */
    private array $thumbnails   = [];
    private string $contentHash = '';

    public function __construct(
        int $userId,
        string $filename,
        string $originalName,
        string $mimeType,
        int $size,
        string $path,
    ) {
        $this->userId       = $userId;
        $this->filename     = $filename;
        $this->originalName = $originalName;
        $this->mimeType     = $mimeType;
        $this->size         = $size;
        $this->path         = $path;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
    public function getFilename(): string
    {
        return $this->filename;
    }
    public function setFilename(string $filename): void
    {
        $this->filename = $filename;
    }
    public function getOriginalName(): string
    {
        return $this->originalName;
    }
    public function setOriginalName(string $originalName): void
    {
        $this->originalName = $originalName;
    }
    public function getMimeType(): string
    {
        return $this->mimeType;
    }
    public function setMimeType(string $mimeType): void
    {
        $this->mimeType = $mimeType;
    }
    public function getSize(): int
    {
        return $this->size;
    }
    public function setSize(int $size): void
    {
        $this->size = $size;
    }
    public function getPath(): string
    {
        return $this->path;
    }
    public function getDisk(): string
    {
        return $this->disk;
    }
    public function getAltText(): ?string
    {
        return $this->altText;
    }
    public function getTitle(): ?string
    {
        return $this->title;
    }
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /** @return array<string, string> */
    public function getAltTextI18n(): array
    {
        return $this->altTextI18n;
    }

    /** @return array<string, string> */
    public function getDescriptionI18n(): array
    {
        return $this->descriptionI18n;
    }

    /** @param array<string, string> $map */
    public function setAltTextI18n(array $map): void
    {
        $this->altTextI18n = $map;
    }

    /** @param array<string, string> $map */
    public function setDescriptionI18n(array $map): void
    {
        $this->descriptionI18n = $map;
    }

    public function getLocalizedAltText(string $locale, string $defaultLocale, bool $fallback = true): ?string
    {
        $locale        = strtolower(trim($locale));
        $defaultLocale = strtolower(trim($defaultLocale));

        if ($locale === $defaultLocale) {
            return $this->altText;
        }

        $localized = trim($this->altTextI18n[$locale] ?? '');
        if ($localized !== '') {
            return $localized;
        }

        if ($fallback && $this->altText !== null && trim($this->altText) !== '') {
            return $this->altText;
        }

        return null;
    }

    public function getLocalizedDescription(string $locale, string $defaultLocale, bool $fallback = true): ?string
    {
        $locale        = strtolower(trim($locale));
        $defaultLocale = strtolower(trim($defaultLocale));

        if ($locale === $defaultLocale) {
            return $this->description;
        }

        $localized = trim($this->descriptionI18n[$locale] ?? '');
        if ($localized !== '') {
            return $localized;
        }

        if ($fallback && $this->description !== null && trim($this->description) !== '') {
            return $this->description;
        }

        return null;
    }

    public function getSearchableText(): string
    {
        $parts = array_filter([
            $this->originalName,
            $this->title,
            $this->altText,
            $this->description,
            ...array_values($this->altTextI18n),
            ...array_values($this->descriptionI18n),
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        return mb_strtolower(implode(' ', $parts), 'UTF-8');
    }

    /** @return array<string, string> */
    public function getThumbnails(): array
    {
        return $this->thumbnails;
    }

    public function setAltText(?string $alt): void
    {
        $this->altText = $alt;
    }
    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }
    public function setDescription(?string $desc): void
    {
        $this->description = $desc;
    }
    /** @param array<string, string> $thumbs */
    public function setThumbnails(array $thumbs): void
    {
        $this->thumbnails = $thumbs;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $hash): void
    {
        $this->contentHash = $hash;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mimeType, 'video/');
    }

    public function getUrl(string $baseUrl = '/uploads'): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($this->path, '/');
    }

    public function getThumbnailUrl(string $size = 'medium', string $baseUrl = '/uploads'): ?string
    {
        $thumb = $this->thumbnails[$size] ?? null;
        return $thumb ? rtrim($baseUrl, '/') . '/' . ltrim($thumb, '/') : null;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $m = new self(
            (int) $row['user_id'],
            $row['filename'],
            $row['original_name'],
            $row['mime_type'],
            (int) $row['size'],
            $row['path'],
        );
        $m->id              = (int) $row['id'];
        $m->disk            = $row['disk']        ?? 'local';
        $m->altText         = $row['alt_text']    ?? null;
        $m->title           = $row['title']       ?? null;
        $m->description     = $row['description'] ?? null;
        $m->altTextI18n     = self::decodeI18nMap($row['alt_text_i18n'] ?? null);
        $m->descriptionI18n = self::decodeI18nMap($row['description_i18n'] ?? null);
        $m->thumbnails      = !empty($row['thumbnails']) ? json_decode($row['thumbnails'], true) : [];
        $m->contentHash     = $row['content_hash'] ?? '';
        if (!empty($row['created_at'])) {
            $m->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $m->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }
        return $m;
    }

    /** @return array<string, string> */
    private static function decodeI18nMap(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '' || !json_validate($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $code => $value) {
            if (!is_string($code) || !is_string($value)) {
                continue;
            }
            $code  = strtolower(trim($code));
            $value = trim($value);
            if ($code !== '' && $value !== '') {
                $map[$code] = $value;
            }
        }

        return $map;
    }
}
