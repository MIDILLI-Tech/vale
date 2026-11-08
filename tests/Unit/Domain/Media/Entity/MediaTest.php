<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Media\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Media\Entity\Media;

final class MediaTest extends TestCase
{
    public function testLocalizedAltTextUsesTranslation(): void
    {
        $media = new Media(1, 'file.jpg', 'file.jpg', 'image/jpeg', 100, '2026/file.jpg');
        $media->setAltText('English alt');
        $media->setAltTextI18n(['tr' => 'Turkish alt']);

        self::assertSame('Turkish alt', $media->getLocalizedAltText('tr', 'en'));
    }

    public function testLocalizedAltTextFallsBackToDefault(): void
    {
        $media = new Media(1, 'file.jpg', 'file.jpg', 'image/jpeg', 100, '2026/file.jpg');
        $media->setAltText('English alt');

        self::assertSame('English alt', $media->getLocalizedAltText('tr', 'en'));
    }

    public function testLocalizedAltTextNoFallback(): void
    {
        $media = new Media(1, 'file.jpg', 'file.jpg', 'image/jpeg', 100, '2026/file.jpg');
        $media->setAltText('English alt');

        self::assertNull($media->getLocalizedAltText('tr', 'en', false));
    }

    public function testLocalizedAltTextSameLocaleAsDefault(): void
    {
        $media = new Media(1, 'file.jpg', 'file.jpg', 'image/jpeg', 100, '2026/file.jpg');
        $media->setAltText('English alt');

        self::assertSame('English alt', $media->getLocalizedAltText('en', 'en'));
    }

    public function testLocalizedDescription(): void
    {
        $media = new Media(1, 'f.jpg', 'f.jpg', 'image/jpeg', 100, '2026/f.jpg');
        $media->setDescription('English desc');
        $media->setDescriptionI18n(['tr' => 'Turkce aciklama']);

        self::assertSame('Turkce aciklama', $media->getLocalizedDescription('tr', 'en'));
        self::assertSame('English desc', $media->getLocalizedDescription('en', 'en'));
        self::assertNull($media->getLocalizedDescription('fr', 'en', false));
    }

    public function testLocalizedDescriptionFallsBack(): void
    {
        $media = new Media(1, 'f.jpg', 'f.jpg', 'image/jpeg', 100, '2026/f.jpg');
        $media->setDescription('English desc');

        self::assertSame('English desc', $media->getLocalizedDescription('tr', 'en'));
    }

    public function testDecodeI18nMapFromRow(): void
    {
        $media = Media::fromRow([
            'id'               => 1,
            'user_id'          => 1,
            'filename'         => 'file.jpg',
            'original_name'    => 'file.jpg',
            'mime_type'        => 'image/jpeg',
            'size'             => 100,
            'path'             => '2026/file.jpg',
            'alt_text'         => 'EN',
            'alt_text_i18n'    => '{"tr":"TR","fr":"FR"}',
            'description_i18n' => null,
            'thumbnails'       => null,
        ]);

        self::assertSame(['tr' => 'TR', 'fr' => 'FR'], $media->getAltTextI18n());
        self::assertSame('TR', $media->getLocalizedAltText('tr', 'en'));
    }

    public function testSearchableTextIncludesI18nValues(): void
    {
        $media = new Media(1, 'fish.jpg', 'fish.jpg', 'image/jpeg', 100, '2026/fish.jpg');
        $media->setDescription('fresh fish');
        $media->setDescriptionI18n(['tr' => 'taze balik']);

        self::assertStringContainsString('fresh fish', $media->getSearchableText());
        self::assertStringContainsString('taze balik', $media->getSearchableText());
    }

    public function testConstructorDefaults(): void
    {
        $media = new Media(1, 'file.jpg', 'file.jpg', 'image/jpeg', 100, '2026/file.jpg');

        self::assertSame(1, $media->getUserId());
        self::assertSame('file.jpg', $media->getFilename());
        self::assertSame('file.jpg', $media->getOriginalName());
        self::assertSame('image/jpeg', $media->getMimeType());
        self::assertSame(100, $media->getSize());
        self::assertSame('2026/file.jpg', $media->getPath());
        self::assertSame('local', $media->getDisk());
        self::assertNull($media->getAltText());
        self::assertNull($media->getTitle());
        self::assertNull($media->getDescription());
        self::assertSame([], $media->getAltTextI18n());
        self::assertSame([], $media->getDescriptionI18n());
        self::assertSame([], $media->getThumbnails());
    }

    public function testSetters(): void
    {
        $media = new Media(1, 'a.jpg', 'a.jpg', 'image/jpeg', 100, 'path/a.jpg');

        $media->setFilename('b.jpg');
        $media->setOriginalName('b.jpg');
        $media->setMimeType('image/png');
        $media->setSize(200);
        $media->setAltText('Alt');
        $media->setTitle('Title');
        $media->setDescription('Desc');
        $media->setThumbnails(['medium' => '2026/b_thumb.jpg']);

        self::assertSame('b.jpg', $media->getFilename());
        self::assertSame('b.jpg', $media->getOriginalName());
        self::assertSame('image/png', $media->getMimeType());
        self::assertSame(200, $media->getSize());
        self::assertSame('Alt', $media->getAltText());
        self::assertSame('Title', $media->getTitle());
        self::assertSame('Desc', $media->getDescription());
        self::assertSame(['medium' => '2026/b_thumb.jpg'], $media->getThumbnails());
    }

    public function testIsImage(): void
    {
        $img = new Media(1, 'a.jpg', 'a.jpg', 'image/jpeg', 100, 'p');
        self::assertTrue($img->isImage());

        $pdf = new Media(1, 'a.pdf', 'a.pdf', 'application/pdf', 100, 'p');
        self::assertFalse($pdf->isImage());
    }

    public function testGetUrl(): void
    {
        $media = new Media(1, 'a.jpg', 'a.jpg', 'image/jpeg', 100, '2026/a.jpg');

        self::assertSame('/uploads/2026/a.jpg', $media->getUrl());
        self::assertSame('/files/2026/a.jpg', $media->getUrl('/files'));
    }

    public function testGetThumbnailUrl(): void
    {
        $media = new Media(1, 'a.jpg', 'a.jpg', 'image/jpeg', 100, '2026/a.jpg');
        $media->setThumbnails(['medium' => '2026/a_med.jpg']);

        self::assertSame('/uploads/2026/a_med.jpg', $media->getThumbnailUrl('medium'));
        self::assertNull($media->getThumbnailUrl('large'));
    }

    public function testFromRow(): void
    {
        $media = Media::fromRow([
            'id'               => '5',
            'user_id'          => '3',
            'filename'         => 'photo.jpg',
            'original_name'    => 'My Photo.jpg',
            'mime_type'        => 'image/jpeg',
            'size'             => '5000',
            'path'             => '2024/photo.jpg',
            'disk'             => 's3',
            'alt_text'         => 'Photo',
            'title'            => 'My Photo',
            'description'      => 'A photo',
            'alt_text_i18n'    => '{"tr":"Foto"}',
            'description_i18n' => '{"tr":"Bir foto"}',
            'thumbnails'       => '{"small":"2024/photo_s.jpg"}',
            'created_at'       => '2024-01-01 00:00:00',
            'updated_at'       => '2024-06-01 00:00:00',
        ]);

        self::assertSame(5, $media->getId());
        self::assertSame(3, $media->getUserId());
        self::assertSame('s3', $media->getDisk());
        self::assertSame('Photo', $media->getAltText());
        self::assertSame('My Photo', $media->getTitle());
        self::assertSame(['tr' => 'Foto'], $media->getAltTextI18n());
        self::assertSame(['tr' => 'Bir foto'], $media->getDescriptionI18n());
        self::assertSame(['small' => '2024/photo_s.jpg'], $media->getThumbnails());
        self::assertInstanceOf(\DateTimeImmutable::class, $media->getCreatedAt());
    }

    public function testDecodeI18nMapInvalidJson(): void
    {
        $media = Media::fromRow([
            'id'               => '1',
            'user_id'          => '1',
            'filename'         => 'f.jpg',
            'original_name'    => 'f.jpg',
            'mime_type'        => 'image/jpeg',
            'size'             => '100',
            'path'             => 'p',
            'alt_text_i18n'    => 'not-json',
            'description_i18n' => '{}',
        ]);

        self::assertSame([], $media->getAltTextI18n());
        self::assertSame([], $media->getDescriptionI18n());
        self::assertSame([], $media->getThumbnails());
    }

    public function testSearchableTextEmpty(): void
    {
        $media = new Media(1, 'f.jpg', 'f.jpg', 'image/jpeg', 100, 'p');
        self::assertStringContainsString('f.jpg', $media->getSearchableText());
    }
}
