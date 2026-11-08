<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Admin;

/**
 * Merges the saved nav order (JSON from settings) with the canonical
 * nav definition (config/admin-nav.php).
 *
 * Rules:
 * - Saved order is an array of top-level item IDs.
 * - Any canonical item not present in the saved order is appended at the end.
 * - Any saved ID that no longer exists in the canonical nav is silently dropped.
 * - Core and plugin children of groups / collapsibles may be reordered inside
 *   their own group, but a child cannot be moved to another group.
 */
final class NavOrderService
{
    /** @var list<array<string,mixed>> */
    private array $canonical;

    public function __construct()
    {
        $this->canonical = require dirname(__DIR__, 3) . '/config/admin-nav.php';
    }

    /**
     * Returns the ordered nav array ready for Twig.
     *
     * @param  string $savedJson  Value of the `admin_nav_order` setting (may be empty).
     * @return list<array<string,mixed>>
     */
    public function getOrderedGroups(string $savedJson): array
    {
        if ($savedJson === '') {
            return $this->canonical;
        }

        $savedOrder = json_decode($savedJson, true);
        if (!is_array($savedOrder)) {
            return $this->canonical;
        }

        // Index canonical by id for O(1) lookup
        $canonicalById = [];
        foreach ($this->canonical as $item) {
            $canonicalById[$item['id']] = $item;
        }

        $ordered = [];

        // First pass: items in saved order that still exist
        foreach ($savedOrder as $id) {
            if (isset($canonicalById[$id])) {
                $ordered[] = $canonicalById[$id];
                unset($canonicalById[$id]);
            }
        }

        // Second pass: append any new canonical items not yet in saved order
        foreach ($canonicalById as $item) {
            $ordered[] = $item;
        }

        return $ordered;
    }

    /**
     * Validate a group => child-id ordering submitted from the sidebar.
     * Every known child may be reordered inside its own group; children that
     * belong to another group are dropped.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, string> $pluginGroups plugin id => group id
     * @return array<string, list<string>>
     */
    public function sanitizeChildOrder(array $submitted, array $pluginGroups = []): array
    {
        $canonical = $this->canonicalChildren();
        $sanitized = [];

        foreach ($canonical as $groupId => $coreIds) {
            $raw = $submitted[$groupId] ?? null;
            if (!is_array($raw)) {
                continue;
            }

            $allowed = $coreIds;
            foreach ($pluginGroups as $pluginId => $pluginGroup) {
                if ($pluginGroup === $groupId) {
                    $allowed[] = $pluginId;
                }
            }

            $ids = [];
            foreach ($raw as $id) {
                if (!is_string($id) || !in_array($id, $allowed, true) || in_array($id, $ids, true)) {
                    continue;
                }
                $ids[] = $id;
            }

            if ($ids === []) {
                continue;
            }

            $sanitized[$groupId] = $ids;
        }

        return $sanitized;
    }

    /**
     * Apply saved group-internal ordering. Every child may be reordered inside
     * its own group; the canonical group membership is preserved.
     *
     * @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>>
     */
    public function applyChildOrder(array $groups, string $savedJson): array
    {
        if ($savedJson === '') {
            return $groups;
        }

        $saved = json_decode($savedJson, true);
        if (!is_array($saved)) {
            return $groups;
        }

        $canonical = $this->canonicalChildren();

        foreach ($groups as &$group) {
            $groupId  = (string) ($group['id'] ?? '');
            $children = $group['children'] ?? null;
            $savedIds = $saved[$groupId]   ?? null;

            if (!is_array($children) || !is_array($savedIds) || !isset($canonical[$groupId])) {
                continue;
            }

            $byId = [];
            foreach ($children as $child) {
                if (is_array($child) && isset($child['id'])) {
                    $byId[(string) $child['id']] = $child;
                }
            }

            $ids = [];
            foreach ($savedIds as $id) {
                if (!is_string($id) || !isset($byId[$id]) || in_array($id, $ids, true)) {
                    continue;
                }
                $ids[] = $id;
            }

            if ($ids === []) {
                continue;
            }

            foreach (array_keys($byId) as $id) {
                if (!in_array((string) $id, $ids, true)) {
                    $ids[] = (string) $id;
                }
            }

            $group['children'] = array_map(
                static fn (string $id): array => $byId[$id],
                $ids,
            );
        }
        unset($group);

        return $groups;
    }

    /**
     * @return array<string, list<string>>
     */
    private function canonicalChildren(): array
    {
        $children = [];
        foreach ($this->canonical as $item) {
            if (!isset($item['children']) || !is_array($item['children'])) {
                continue;
            }
            $children[(string) $item['id']] = array_map(
                static fn (array $child): string => (string) $child['id'],
                $item['children'],
            );
        }

        return $children;
    }

    /**
     * Returns a flat list of top-level IDs in canonical order.
     *
     * @return list<string>
     */
    public function getDefaultOrder(): array
    {
        return array_map(static fn (array $item) => $item['id'], $this->canonical);
    }

    /**
     * Filter a submitted top-level order down to ids this installation knows:
     * the canonical nav plus ids declared by enabled plugins (`plugin-<slug>`).
     *
     * @param array<mixed> $ids
     * @param list<string> $extraIds
     * @return list<string>
     */
    public function sanitizeOrder(array $ids, array $extraIds = []): array
    {
        $valid = array_merge($this->getDefaultOrder(), $extraIds);

        return array_values(array_filter(
            $ids,
            static fn ($id): bool => is_string($id) && in_array($id, $valid, true),
        ));
    }

    /**
     * Returns all valid top-level IDs (used for input validation).
     *
     * @return list<string>
     */
    public function getValidIds(): array
    {
        return $this->getDefaultOrder();
    }
}
