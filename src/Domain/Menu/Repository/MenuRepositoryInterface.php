<?php

declare(strict_types=1);

namespace ValeApp\Domain\Menu\Repository;

use ValeApp\Domain\Menu\Entity\Menu;
use ValeApp\Domain\Menu\Entity\MenuItem;

interface MenuRepositoryInterface
{
    public function findById(int $id): ?Menu;

    /** @return Menu[] */
    public function findAll(): array;

    public function findByLocation(string $location): ?Menu;

    /** @return Menu[] */
    public function findAllByLocation(string $location): array;

    public function save(Menu $menu): int;

    public function delete(int $id): void;

    public function slugExists(string $slug, ?int $excludeId = null): bool;

    // Menu items

    /** @return MenuItem[] */
    public function findItemsByMenuId(int $menuId): array;

    public function saveItem(MenuItem $item): int;

    public function deleteItem(int $itemId): void;

    public function deleteItemsByMenuId(int $menuId): void;

    /** @param MenuItem[] $items */
    public function replaceItems(int $menuId, array $items): void;
}
