<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Menu;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Menu\LegalFooterMenuService;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Menu\Entity\Menu;
use ValeApp\Domain\Menu\Entity\MenuItem;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class LegalFooterMenuServiceTest extends TestCase
{
    private const SETTINGS = [
        'policy_page_imprint' => 'imprint',
        'policy_page_privacy' => 'privacy-policy',
        'policy_page_terms'   => 'terms-of-service',
        'policy_page_returns' => 'return-policy',
    ];

    public function testCreatesFooterMenuWithLegalHeadingAndSelectedPages(): void
    {
        $menus = new InMemoryMenuRepository();
        $posts = $this->pageRepository(published: true);

        $service = new LegalFooterMenuService($menus, $posts);
        $result  = $service->apply(self::SETTINGS);

        self::assertTrue($result['created']);
        self::assertSame(4, $result['added']);
        self::assertSame('Footer', $result['menu_name']);

        $menu = $menus->findByLocation('footer');
        self::assertNotNull($menu);

        $items = $menus->findItemsByMenuId((int) $menu->getId());
        self::assertCount(5, $items);

        $heading = array_values(array_filter(
            $items,
            static fn (MenuItem $item): bool => $item->getParentId() === null,
        ))[0];
        self::assertSame('Legal', $heading->getLabel());
        self::assertNull($heading->getUrl());
        self::assertSame(LegalFooterMenuService::MANAGED_CLASS, $heading->getCssClass());

        $children = array_values(array_filter(
            $items,
            static fn (MenuItem $item): bool => $item->getParentId() === $heading->getId(),
        ));
        self::assertCount(4, $children);
        self::assertContains('/privacy-policy', array_map(static fn (MenuItem $item): ?string => $item->getUrl(), $children));
        self::assertContains('/imprint', array_map(static fn (MenuItem $item): ?string => $item->getUrl(), $children));
    }

    public function testWithdrawalPageJoinsTheLegalColumnWhenEnabled(): void
    {
        $menus   = new InMemoryMenuRepository();
        $service = new LegalFooterMenuService($menus, $this->pageRepository(true));

        $result = $service->apply(self::SETTINGS + ['compliance_withdrawal_function' => '1']);
        self::assertSame(5, $result['added']);

        $menu = $menus->findByLocation('footer');
        $urls = array_map(
            static fn (MenuItem $item): ?string => $item->getUrl(),
            $menus->findItemsByMenuId((int) $menu->getId()),
        );
        self::assertContains('/withdrawal', $urls);

        $again = $service->apply(self::SETTINGS + ['compliance_withdrawal_function' => '1']);
        self::assertSame(0, $again['added']);
    }

    public function testSecondApplyDoesNotDuplicateItems(): void
    {
        $menus   = new InMemoryMenuRepository();
        $service = new LegalFooterMenuService($menus, $this->pageRepository(true));

        $service->apply(self::SETTINGS);
        $result = $service->apply(self::SETTINGS);

        self::assertFalse($result['created']);
        self::assertSame(0, $result['added']);

        $menu  = $menus->findByLocation('footer');
        $items = $menus->findItemsByMenuId((int) $menu->getId());
        self::assertCount(5, $items);
    }

    public function testSkipsUnpublishedPages(): void
    {
        $menus   = new InMemoryMenuRepository();
        $service = new LegalFooterMenuService($menus, $this->pageRepository(false));

        $result = $service->apply(self::SETTINGS);

        self::assertSame(0, $result['added']);
        $items = $menus->findItemsByMenuId($result['menu_id']);
        self::assertCount(1, $items);
        self::assertSame('Legal', $items[0]->getLabel());
        self::assertSame([], array_filter(
            $items,
            static fn (MenuItem $item): bool => $item->getParentId() !== null,
        ));
    }

    public function testStatusReportsHeadingAndLinkCount(): void
    {
        $menus   = new InMemoryMenuRepository();
        $service = new LegalFooterMenuService($menus, $this->pageRepository(true));

        self::assertNull($service->status()['menu_id']);

        $service->apply(self::SETTINGS);
        $status = $service->status();

        self::assertTrue($status['has_heading']);
        self::assertSame(4, $status['links']);
    }

    private function pageRepository(bool $published): PostRepositoryInterface
    {
        $pages = [];
        $next  = 10;

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findBySlug')->willReturnCallback(function (Slug $slug) use (&$pages, &$next, $published): Post {
            $key = (string) $slug;
            if (!isset($pages[$key])) {
                $page = $this->createStub(Post::class);
                $page->method('isPublished')->willReturn($published);
                $page->method('getId')->willReturn(++$next);
                $page->method('getSlug')->willReturn($slug);
                $pages[$key] = $page;
            }

            return $pages[$key];
        });
        $posts->method('buildPagePath')->willReturnCallback(
            static fn (Post $page): string => (string) $page->getSlug(),
        );

        return $posts;
    }
}

final class InMemoryMenuRepository implements MenuRepositoryInterface
{
    /** @var array<int, Menu> */
    private array $menus = [];

    /** @var array<int, MenuItem> */
    private array $items = [];

    private int $nextMenuId = 1;
    private int $nextItemId = 100;

    public function findById(int $id): ?Menu
    {
        return $this->menus[$id] ?? null;
    }

    /** @return Menu[] */
    public function findAll(): array
    {
        return array_values($this->menus);
    }

    public function findByLocation(string $location): ?Menu
    {
        foreach ($this->menus as $menu) {
            if ($menu->getLocation() === $location) {
                return $menu;
            }
        }

        return null;
    }

    /** @return Menu[] */
    public function findAllByLocation(string $location): array
    {
        return array_values(array_filter(
            $this->menus,
            static fn (Menu $menu): bool => $menu->getLocation() === $location,
        ));
    }

    public function save(Menu $menu): int
    {
        if ($menu->getId() === null) {
            $menu->setId($this->nextMenuId++);
        }
        $this->menus[(int) $menu->getId()] = $menu;

        return (int) $menu->getId();
    }

    public function delete(int $id): void
    {
        unset($this->menus[$id]);
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        return false;
    }

    /** @return MenuItem[] */
    public function findItemsByMenuId(int $menuId): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (MenuItem $item): bool => $item->getMenuId() === $menuId,
        ));
    }

    public function saveItem(MenuItem $item): int
    {
        if ($item->getId() === null) {
            $item->setId($this->nextItemId++);
        }
        $this->items[(int) $item->getId()] = $item;

        return (int) $item->getId();
    }

    public function deleteItem(int $itemId): void
    {
        unset($this->items[$itemId]);
    }

    public function deleteItemsByMenuId(int $menuId): void
    {
        $this->items = array_filter(
            $this->items,
            static fn (MenuItem $item): bool => $item->getMenuId() !== $menuId,
        );
    }

    /** @param MenuItem[] $items */
    public function replaceItems(int $menuId, array $items): void
    {
        $this->deleteItemsByMenuId($menuId);
        foreach ($items as $item) {
            $this->saveItem($item);
        }
    }
}
