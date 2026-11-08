<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Content\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Content\Entity\Category;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class CategoryTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $cat = new Category('News', new Slug('news'));

        self::assertSame('News', $cat->getName());
        self::assertSame('news', (string) $cat->getSlug());
        self::assertSame('post', $cat->getContentType());
        self::assertSame('en', $cat->getLanguage());
        self::assertNull($cat->getDescription());
        self::assertNull($cat->getParentId());
        self::assertSame(0, $cat->getSortOrder());
        self::assertNull($cat->getTranslationOf());
        self::assertSame(0, $cat->depth);
    }

    public function testSetters(): void
    {
        $cat = new Category('News', new Slug('news'));

        $cat->setName('Blog');
        $cat->setSlug(new Slug('blog'));
        $cat->setDescription('Our blog');
        $cat->setParentId(5);
        $cat->setSortOrder(3);
        $cat->setContentType('page');
        $cat->setLanguage('tr');
        $cat->setTranslationOf(7);

        self::assertSame('Blog', $cat->getName());
        self::assertSame('blog', (string) $cat->getSlug());
        self::assertSame('Our blog', $cat->getDescription());
        self::assertSame(5, $cat->getParentId());
        self::assertSame(3, $cat->getSortOrder());
        self::assertSame('page', $cat->getContentType());
        self::assertSame('tr', $cat->getLanguage());
        self::assertSame(7, $cat->getTranslationOf());
    }

    public function testFromRow(): void
    {
        $cat = Category::fromRow([
            'id'               => '12',
            'name'             => 'Tutorials',
            'slug'             => 'tutorials',
            'content_type'     => 'post',
            'language'         => 'tr',
            'translation_of'   => '4',
            'description'      => 'Tutorial posts',
            'parent_id'        => '2',
            'sort_order'       => '5',
            'created_at'       => '2024-03-10 08:00:00',
            'updated_at'       => '2024-03-11 09:00:00',
        ]);

        self::assertSame(12, $cat->getId());
        self::assertSame('Tutorials', $cat->getName());
        self::assertSame('tutorials', (string) $cat->getSlug());
        self::assertSame('tr', $cat->getLanguage());
        self::assertSame(4, $cat->getTranslationOf());
        self::assertSame('Tutorial posts', $cat->getDescription());
        self::assertSame(2, $cat->getParentId());
        self::assertSame(5, $cat->getSortOrder());
        self::assertInstanceOf(\DateTimeImmutable::class, $cat->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $cat->getUpdatedAt());
    }

    public function testFromRowNullables(): void
    {
        $cat = Category::fromRow([
            'id'   => '1',
            'name' => 'Test',
            'slug' => 'test',
        ]);

        self::assertNull($cat->getDescription());
        self::assertNull($cat->getParentId());
        self::assertNull($cat->getTranslationOf());
        self::assertSame(0, $cat->getSortOrder());
        self::assertNull($cat->getCreatedAt());
        self::assertNull($cat->getUpdatedAt());
    }
}
