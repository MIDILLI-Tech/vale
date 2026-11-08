<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content;

/**
 * Helper class for building hierarchical trees from flat lists.
 */
final class TreeHelper
{
    /**
     * Build a flat list sorted as a tree: parents first, children directly below.
     * Within the same level, sort by sort_order ASC then name ASC.
     * Each item gets a 'depth' property (0 = root, 1 = child, …).
     *
     * @param array<object> $items Flat list of entities with getId(), getParentId()
     * @return array<object>
     */
    public static function buildTree(array $items): array
    {
        $byParent = [];
        foreach ($items as $item) {
            $pid              = method_exists($item, 'getParentId') ? ($item->getParentId() ?? 0) : 0;
            $byParent[$pid][] = $item;
        }

        $result = [];
        $depths = [];
        self::appendChildren($byParent, 0, 0, $result, $depths);

        // Attach depth as a dynamic public property so Twig can access it
        foreach ($result as $i => $item) {
            $item->depth = $depths[$i];
        }

        return $result;
    }

    private static function appendChildren(array &$byParent, int $parentId, int $depth, array &$result, array &$depths): void
    {
        if (!isset($byParent[$parentId])) {
            return;
        }

        foreach ($byParent[$parentId] as $item) {
            $result[] = $item;
            $depths[] = $depth;
            $id       = method_exists($item, 'getId') ? (int) $item->getId() : 0;
            if ($id !== 0) {
                self::appendChildren($byParent, $id, $depth + 1, $result, $depths);
            }
        }
    }
}
