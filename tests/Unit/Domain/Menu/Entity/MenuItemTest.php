<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Menu\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Menu\Entity\MenuItem;

final class MenuItemTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $item = new MenuItem(1, 'Home');

        self::assertSame(1, $item->getMenuId());
        self::assertSame('Home', $item->getLabel());
        self::assertNull($item->getParentId());
        self::assertSame('custom', $item->getType());
        self::assertNull($item->getUrl());
        self::assertSame('_self', $item->getTarget());
        self::assertNull($item->getReferenceId());
        self::assertSame(0, $item->getSortOrder());
        self::assertNull($item->getCssClass());
    }

    public function testSetters(): void
    {
        $item = new MenuItem(1, 'Home');

        $item->setParentId(5);
        $item->setType('page');
        $item->setLabel('About');
        $item->setUrl('/about');
        $item->setTarget('_blank');
        $item->setReferenceId(10);
        $item->setSortOrder(3);
        $item->setCssClass('nav-link');

        self::assertSame(5, $item->getParentId());
        self::assertSame('page', $item->getType());
        self::assertSame('About', $item->getLabel());
        self::assertSame('/about', $item->getUrl());
        self::assertSame('_blank', $item->getTarget());
        self::assertSame(10, $item->getReferenceId());
        self::assertSame(3, $item->getSortOrder());
        self::assertSame('nav-link', $item->getCssClass());
    }

    public function testFromRow(): void
    {
        $item = MenuItem::fromRow([
            'id'            => '8',
            'menu_id'       => '2',
            'parent_id'     => '3',
            'type'          => 'post',
            'label'         => 'My Post',
            'url'           => '/blog/my-post',
            'target'        => '_blank',
            'reference_id'  => '15',
            'sort_order'    => '4',
            'css_class'     => 'highlight',
            'created_at'    => '2024-02-01 10:00:00',
            'updated_at'    => '2024-02-02 11:00:00',
        ]);

        self::assertSame(8, $item->getId());
        self::assertSame(2, $item->getMenuId());
        self::assertSame(3, $item->getParentId());
        self::assertSame('post', $item->getType());
        self::assertSame('My Post', $item->getLabel());
        self::assertSame('/blog/my-post', $item->getUrl());
        self::assertSame('_blank', $item->getTarget());
        self::assertSame(15, $item->getReferenceId());
        self::assertSame(4, $item->getSortOrder());
        self::assertSame('highlight', $item->getCssClass());
        self::assertInstanceOf(\DateTimeImmutable::class, $item->getCreatedAt());
    }

    public function testFromRowNullParent(): void
    {
        $item = MenuItem::fromRow([
            'id'            => '1',
            'menu_id'       => '1',
            'label'         => 'X',
            'parent_id'     => null,
            'type'          => 'custom',
            'url'           => null,
            'target'        => '_self',
            'reference_id'  => null,
            'sort_order'    => 0,
            'css_class'     => null,
        ]);

        self::assertNull($item->getParentId());
        self::assertNull($item->getUrl());
        self::assertNull($item->getReferenceId());
        self::assertNull($item->getCssClass());
        self::assertSame('custom', $item->getType());
        self::assertSame('_self', $item->getTarget());
        self::assertSame(0, $item->getSortOrder());
    }
}
