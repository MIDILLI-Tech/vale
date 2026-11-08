<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Content\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class ContentTypeTest extends TestCase
{
    public function testPublicBasePathWithoutPrefixUsesTypeSlugAtRoot(): void
    {
        $type = new ContentType('Test CPT', new Slug('testcpt'));

        self::assertSame('/testcpt', $type->getPublicBasePath());
        self::assertSame('/testcpt', ContentType::buildPublicBasePath(null, 'testcpt'));
    }

    public function testPublicBasePathWithPrefixUsesNamespaceAndTypeSlug(): void
    {
        $type = new ContentType('Test CPT', new Slug('testcpt'));
        $type->setRoutePrefix('/d');

        self::assertSame('/d/testcpt', $type->getPublicBasePath());
        self::assertSame('/d/secondcpt', ContentType::buildPublicBasePath('/d', 'secondcpt'));
    }

    public function testBuildPublicBasePathEmptySlugReturnsRoot(): void
    {
        self::assertSame('/', ContentType::buildPublicBasePath(null, ''));
        self::assertSame('/', ContentType::buildPublicBasePath('', ''));
    }

    public function testBuildPublicBasePathPrefixOnly(): void
    {
        self::assertSame('/', ContentType::buildPublicBasePath('/d', ''));
    }

    public function testBuildPublicBasePathTrimsSlashes(): void
    {
        self::assertSame('/prefix/slug', ContentType::buildPublicBasePath('/prefix/', '/slug'));
    }

    public function testConstructorAndDefaults(): void
    {
        $type = new ContentType('My Type', new Slug('my-type'));

        self::assertSame('My Type', $type->getName());
        self::assertSame('my-type', (string) $type->getSlug());
        self::assertNull($type->getDescription());
        self::assertSame('document-text', $type->getIcon());
        self::assertNull($type->getRoutePrefix());
        self::assertNull($type->getCategoryPrefix());
        self::assertNull($type->getTagPrefix());
        self::assertSame(['title', 'editor', 'thumbnail', 'excerpt', 'author', 'comments', 'revisions', 'categories', 'tags', 'i18n'], $type->getSupports());
        self::assertSame([], $type->getFieldSchema());
        self::assertFalse($type->isSystem());
        self::assertTrue($type->isActive());
    }

    public function testSetters(): void
    {
        $type = new ContentType('X', new Slug('x'));

        $type->setName('New');
        $type->setSlug(new Slug('new'));
        $type->setDescription('Desc');
        $type->setIcon('star');
        $type->setRoutePrefix('/c');
        $type->setSupports(['title', 'editor']);
        $type->setFieldSchema([['key' => 'field1']]);
        $type->setIsSystem(true);
        $type->setIsActive(false);

        self::assertSame('New', $type->getName());
        self::assertSame('new', (string) $type->getSlug());
        self::assertSame('Desc', $type->getDescription());
        self::assertSame('star', $type->getIcon());
        self::assertSame('/c', $type->getRoutePrefix());
        self::assertSame(['title', 'editor'], $type->getSupports());
        self::assertSame([['key' => 'field1']], $type->getFieldSchema());
        self::assertTrue($type->isSystem());
        self::assertFalse($type->isActive());
    }

    public function testFromRow(): void
    {
        $type = ContentType::fromRow([
            'id'            => '5',
            'name'          => 'Portfolio',
            'slug'          => 'portfolio',
            'description'   => 'My projects',
            'icon'          => 'briefcase',
            'route_prefix'  => '/work',
            'supports'      => '["title","editor","thumbnail"]',
            'field_schema'  => '[{"key":"url","type":"text"}]',
            'is_system'     => '1',
            'is_active'     => '0',
            'created_at'    => '2024-01-01 00:00:00',
            'updated_at'    => '2024-06-01 00:00:00',
        ]);

        self::assertSame(5, $type->getId());
        self::assertSame('Portfolio', $type->getName());
        self::assertSame('portfolio', (string) $type->getSlug());
        self::assertSame('My projects', $type->getDescription());
        self::assertSame('briefcase', $type->getIcon());
        self::assertSame('/work', $type->getRoutePrefix());
        self::assertSame(['title', 'editor', 'thumbnail'], $type->getSupports());
        self::assertSame([['key' => 'url', 'type' => 'text']], $type->getFieldSchema());
        self::assertTrue($type->isSystem());
        self::assertFalse($type->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $type->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $type->getUpdatedAt());
    }

    public function testFromRowDefaults(): void
    {
        $type = ContentType::fromRow([
            'id'   => '1',
            'name' => 'X',
            'slug' => 'x',
        ]);

        self::assertNull($type->getDescription());
        self::assertSame('document-text', $type->getIcon());
        self::assertSame([], $type->getSupports());
        self::assertSame([], $type->getFieldSchema());
        self::assertFalse($type->isSystem());
        self::assertTrue($type->isActive());
        self::assertNull($type->getRoutePrefix());
        self::assertNull($type->getCreatedAt());
    }
}
