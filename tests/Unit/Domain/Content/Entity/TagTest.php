<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Content\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Content\Entity\Tag;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class TagTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $tag = new Tag('PHP', new Slug('php'));

        self::assertSame('PHP', $tag->getName());
        self::assertSame('php', (string) $tag->getSlug());
        self::assertSame('post', $tag->getContentType());
        self::assertSame('en', $tag->getLanguage());
        self::assertNull($tag->getTranslationOf());
        self::assertNull($tag->getId());
    }

    public function testConstructorWithContentType(): void
    {
        $tag = new Tag('Widget', new Slug('widget'), 'page');

        self::assertSame('page', $tag->getContentType());
    }

    public function testSetters(): void
    {
        $tag = new Tag('PHP', new Slug('php'));

        $tag->setName('Laravel');
        $tag->setSlug(new Slug('laravel'));
        $tag->setContentType('page');
        $tag->setLanguage('tr');
        $tag->setTranslationOf(5);

        self::assertSame('Laravel', $tag->getName());
        self::assertSame('laravel', (string) $tag->getSlug());
        self::assertSame('page', $tag->getContentType());
        self::assertSame('tr', $tag->getLanguage());
        self::assertSame(5, $tag->getTranslationOf());
    }

    public function testFromRow(): void
    {
        $tag = Tag::fromRow([
            'id'              => '10',
            'name'            => 'Symfony',
            'slug'            => 'symfony',
            'content_type'    => 'post',
            'language'        => 'fr',
            'translation_of'  => '3',
            'created_at'      => '2024-01-15 10:00:00',
        ]);

        self::assertSame(10, $tag->getId());
        self::assertSame('Symfony', $tag->getName());
        self::assertSame('symfony', (string) $tag->getSlug());
        self::assertSame('fr', $tag->getLanguage());
        self::assertSame(3, $tag->getTranslationOf());
        self::assertInstanceOf(\DateTimeImmutable::class, $tag->getCreatedAt());
    }

    public function testFromRowDefaults(): void
    {
        $tag = Tag::fromRow([
            'id'   => '1',
            'name' => 'Test',
            'slug' => 'test',
        ]);

        self::assertSame('post', $tag->getContentType());
        self::assertSame('en', $tag->getLanguage());
        self::assertNull($tag->getTranslationOf());
        self::assertNull($tag->getCreatedAt());
    }
}
