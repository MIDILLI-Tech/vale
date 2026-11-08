<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Menu\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Menu\Entity\Menu;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class MenuTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $menu = new Menu('Main Nav', new Slug('main-nav'));

        self::assertSame('Main Nav', $menu->getName());
        self::assertSame('main-nav', (string) $menu->getSlug());
        self::assertSame('en', $menu->getLanguage());
        self::assertNull($menu->getLocation());
        self::assertNull($menu->getTranslationOf());
    }

    public function testSetters(): void
    {
        $menu = new Menu('Main', new Slug('main'));

        $menu->setName('Footer');
        $menu->setSlug(new Slug('footer'));
        $menu->setLocation('footer');
        $menu->setLanguage('tr');
        $menu->setTranslationOf(5);

        self::assertSame('Footer', $menu->getName());
        self::assertSame('footer', (string) $menu->getSlug());
        self::assertSame('footer', $menu->getLocation());
        self::assertSame('tr', $menu->getLanguage());
        self::assertSame(5, $menu->getTranslationOf());
    }

    public function testFromRow(): void
    {
        $menu = Menu::fromRow([
            'id'              => '4',
            'name'            => 'Sidebar',
            'slug'            => 'sidebar',
            'language'        => 'de',
            'translation_of'  => '2',
            'location'        => 'sidebar-left',
            'created_at'      => '2024-05-10 12:00:00',
            'updated_at'      => '2024-05-11 13:00:00',
        ]);

        self::assertSame(4, $menu->getId());
        self::assertSame('Sidebar', $menu->getName());
        self::assertSame('sidebar', (string) $menu->getSlug());
        self::assertSame('de', $menu->getLanguage());
        self::assertSame(2, $menu->getTranslationOf());
        self::assertSame('sidebar-left', $menu->getLocation());
        self::assertInstanceOf(\DateTimeImmutable::class, $menu->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $menu->getUpdatedAt());
    }

    public function testFromRowNullables(): void
    {
        $menu = Menu::fromRow([
            'id'   => '1',
            'name' => 'X',
            'slug' => 'x',
        ]);

        self::assertNull($menu->getLocation());
        self::assertNull($menu->getTranslationOf());
        self::assertSame('en', $menu->getLanguage());
        self::assertNull($menu->getCreatedAt());
    }
}
