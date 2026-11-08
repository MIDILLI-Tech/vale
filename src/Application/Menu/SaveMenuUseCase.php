<?php

declare(strict_types=1);

namespace ValeApp\Application\Menu;

use ValeApp\Domain\Menu\Entity\Menu;
use ValeApp\Domain\Menu\Entity\MenuItem;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Infrastructure\Content\SlugService;

final class SaveMenuUseCase
{
    public function __construct(
        private readonly MenuRepositoryInterface $menus,
        private readonly SlugService $slugs,
    ) {
    }

    /**
     * @param array{name: string, slug?: string, location?: string} $data
     * @param array<int, array{label: string, type?: string, url?: string, target?: string, reference_id?: int|null, parent_id?: int|null, sort_order?: int, css_class?: string}> $items
     */
    public function execute(array $data, array $items = [], ?int $id = null): Menu
    {
        if (empty($data['name'])) {
            throw ValidationException::withMessages(['name' => 'Menu name is required.']);
        }

        if ($id !== null) {
            $menu = $this->menus->findById($id);
            if ($menu === null) {
                throw ValidationException::withMessages(['id' => 'Menu not found.']);
            }
            $menu->setName($data['name']);
            $slug = $this->slugs->unique(
                $data['slug'] ?? $data['name'],
                VL_DB_PREFIX . 'menus',
                'slug',
                $id,
                'language = ?',
                [$menu->getLanguage()]
            );
            $menu->setSlug($slug);

            // Language and translation_of can be updated on edit too
            if (isset($data['language']) && $data['language'] !== '') {
                $menu->setLanguage($data['language']);
            }
            if (isset($data['translation_of'])) {
                $menu->setTranslationOf($data['translation_of'] !== '' ? (int) $data['translation_of'] : null);
            }
        } else {
            $lang = $data['language'] ?? 'en';
            $slug = $this->slugs->unique(
                $data['slug'] ?? $data['name'],
                VL_DB_PREFIX . 'menus',
                'slug',
                null,
                'language = ?',
                [$lang]
            );
            $menu = new Menu($data['name'], $slug);
            $menu->setLanguage($lang);
            $menu->setTranslationOf(isset($data['translation_of']) && $data['translation_of'] !== '' ? (int) $data['translation_of'] : null);
        }

        $menu->setLocation($data['location'] ?? null);
        $this->menus->save($menu);

        // Replace menu items
        if (!empty($items)) {
            $menuItems      = [];
            $parentIndexMap = []; // itemIndex => parent_index
            foreach ($items as $i => $itemData) {
                $mi = new MenuItem($menu->getId(), $itemData['label']);
                $mi->setType($itemData['type'] ?? 'custom');
                $mi->setUrl($itemData['url'] ?? null);
                $mi->setTarget($itemData['target'] ?? '_self');
                $mi->setReferenceId(isset($itemData['reference_id']) && $itemData['reference_id'] !== '' ? (int) $itemData['reference_id'] : null);
                $mi->setSortOrder($itemData['sort_order'] ?? $i);
                $mi->setCssClass($itemData['css_class'] ?? null);
                // parent_index will be resolved to parent_id after all items are saved
                $parentIndexMap[$i] = $itemData['parent_index'] ?? -1;
                $menuItems[]        = $mi;
            }
            $this->menus->replaceItems($menu->getId(), $menuItems);

            // Second pass: resolve parent_index -> parent_id (now all items have DB IDs)
            $needsUpdate = false;
            foreach ($menuItems as $i => $mi) {
                $pIdx = $parentIndexMap[$i];
                if ($pIdx >= 0 && isset($menuItems[$pIdx])) {
                    $mi->setParentId($menuItems[$pIdx]->getId());
                    $needsUpdate = true;
                }
            }
            if ($needsUpdate) {
                foreach ($menuItems as $mi) {
                    if ($mi->getParentId() !== null) {
                        $this->menus->saveItem($mi);
                    }
                }
            }
        }

        return $menu;
    }
}
