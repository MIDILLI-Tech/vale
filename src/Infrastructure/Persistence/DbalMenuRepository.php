<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Menu\Entity\Menu;
use ValeApp\Domain\Menu\Entity\MenuItem;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;

final class DbalMenuRepository implements MenuRepositoryInterface
{
    private static function t(string $n): string
    {
        return VL_DB_PREFIX . $n;
    }

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function findById(int $id): ?Menu
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . self::t('menus') . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );

        return $row !== false ? Menu::fromRow($row) : null;
    }

    /** @inheritDoc */
    public function findAll(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . self::t('menus') . ' ORDER BY name ASC',
        );

        return array_map(fn (array $row) => Menu::fromRow($row), $rows);
    }

    public function findByLocation(string $location): ?Menu
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . self::t('menus') . ' WHERE location = ?',
            [$location],
            [ParameterType::STRING],
        );

        return $row !== false ? Menu::fromRow($row) : null;
    }

    /** @inheritDoc */
    public function findAllByLocation(string $location, ?string $language = null): array
    {
        try {
            $sql    = 'SELECT * FROM ' . self::t('menus') . ' WHERE location = ?';
            $params = [$location];
            $types  = [ParameterType::STRING];

            if ($language !== null) {
                $sql .= ' AND language = ?';
                $params[] = $language;
                $types[]  = ParameterType::STRING;
            }

            $sql .= ' ORDER BY name ASC';

            $rows = $this->db->fetchAllAssociative($sql, $params, $types);

            return array_map(fn (array $row) => Menu::fromRow($row), $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    public function save(Menu $menu): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'name'           => $menu->getName(),
            'language'       => $menu->getLanguage(),
            'translation_of' => $menu->getTranslationOf(),
            'slug'           => (string) $menu->getSlug(),
            'location'       => $menu->getLocation(),
        ];

        if ($menu->isNew()) {
            $data['created_at'] = $now;
            $this->db->insert(self::t('menus'), $data);
            $id = (int) $this->db->lastInsertId();
            $menu->setId($id);
            return $id;
        }

        $data['updated_at'] = $now;
        $this->db->update(self::t('menus'), $data, ['id' => $menu->getId()]);
        return $menu->getId();
    }

    public function delete(int $id): void
    {
        $this->db->delete(self::t('menu_items'), ['menu_id' => $id]);
        $this->db->delete(self::t('menus'), ['id' => $id]);
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM ' . self::t('menus') . ' WHERE slug = ?';
        $params = [$slug];
        $types  = [ParameterType::STRING];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        return (int) $this->db->fetchOne($sql, $params, $types) > 0;
    }

    // ── Menu Items ───────────────────────────────────────────────────

    /** @inheritDoc */
    public function findItemsByMenuId(int $menuId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . self::t('menu_items') . ' WHERE menu_id = ? ORDER BY sort_order ASC',
            [$menuId],
            [ParameterType::INTEGER],
        );

        return array_map(fn (array $row) => MenuItem::fromRow($row), $rows);
    }

    public function saveItem(MenuItem $item): int
    {
        $data = [
            'menu_id'      => $item->getMenuId(),
            'parent_id'    => $item->getParentId(),
            'type'         => $item->getType(),
            'label'        => $item->getLabel(),
            'url'          => $item->getUrl(),
            'target'       => $item->getTarget(),
            'reference_id' => $item->getReferenceId(),
            'sort_order'   => $item->getSortOrder(),
            'css_class'    => $item->getCssClass(),
        ];

        if ($item->isNew()) {
            $this->db->insert(self::t('menu_items'), $data);
            $id = (int) $this->db->lastInsertId();
            $item->setId($id);
            return $id;
        }

        $this->db->update(self::t('menu_items'), $data, ['id' => $item->getId()]);
        return $item->getId();
    }

    public function deleteItem(int $itemId): void
    {
        $this->db->delete(self::t('menu_items'), ['id' => $itemId]);
    }

    public function deleteItemsByMenuId(int $menuId): void
    {
        $this->db->delete(self::t('menu_items'), ['menu_id' => $menuId]);
    }

    /** @inheritDoc */
    public function replaceItems(int $menuId, array $items): void
    {
        $this->deleteItemsByMenuId($menuId);
        foreach ($items as $item) {
            $this->saveItem($item);
        }
    }
}
