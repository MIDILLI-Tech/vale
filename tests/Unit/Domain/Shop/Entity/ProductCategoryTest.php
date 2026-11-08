<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shop\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\ProductCategory;

final class ProductCategoryTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $cat = new ProductCategory('Electronics', new Slug('electronics'));

        self::assertSame('Electronics', $cat->getName());
        self::assertSame('electronics', (string) $cat->getSlug());
        self::assertNull($cat->getParentId());
        self::assertSame('en', $cat->getLanguage());
        self::assertNull($cat->getTranslationOf());
        self::assertNull($cat->getDescription());
        self::assertNull($cat->getImageId());
        self::assertSame(0, $cat->getSortOrder());
        self::assertSame(0, $cat->depth);
    }

    public function testSetters(): void
    {
        $cat = new ProductCategory('X', new Slug('x'));

        $cat->setName('Books');
        $cat->setSlug(new Slug('books'));
        $cat->setParentId(3);
        $cat->setLanguage('tr');
        $cat->setTranslationOf(1);
        $cat->setDescription('All books');
        $cat->setImageId(10);
        $cat->setSortOrder(5);

        self::assertSame('Books', $cat->getName());
        self::assertSame('books', (string) $cat->getSlug());
        self::assertSame(3, $cat->getParentId());
        self::assertSame('tr', $cat->getLanguage());
        self::assertSame(1, $cat->getTranslationOf());
        self::assertSame('All books', $cat->getDescription());
        self::assertSame(10, $cat->getImageId());
        self::assertSame(5, $cat->getSortOrder());
    }

    public function testFromRow(): void
    {
        $cat = ProductCategory::fromRow([
            'id'               => '4',
            'name'             => 'Shoes',
            'slug'             => 'shoes',
            'language'         => 'fr',
            'translation_of'   => '2',
            'parent_id'        => '1',
            'description'      => 'Footwear',
            'image_id'         => '7',
            'sort_order'       => '3',
            'created_at'       => '2024-05-01 10:00:00',
        ]);

        self::assertSame(4, $cat->getId());
        self::assertSame('Shoes', $cat->getName());
        self::assertSame('shoes', (string) $cat->getSlug());
        self::assertSame('fr', $cat->getLanguage());
        self::assertSame(2, $cat->getTranslationOf());
        self::assertSame(1, $cat->getParentId());
        self::assertSame('Footwear', $cat->getDescription());
        self::assertSame(7, $cat->getImageId());
        self::assertSame(3, $cat->getSortOrder());
        self::assertInstanceOf(\DateTimeImmutable::class, $cat->getCreatedAt());
    }

    public function testFromRowNullables(): void
    {
        $cat = ProductCategory::fromRow([
            'id'   => '1',
            'name' => 'X',
            'slug' => 'x',
        ]);

        self::assertNull($cat->getParentId());
        self::assertNull($cat->getTranslationOf());
        self::assertNull($cat->getDescription());
        self::assertNull($cat->getImageId());
        self::assertSame(0, $cat->getSortOrder());
        self::assertNull($cat->getCreatedAt());
    }
}
