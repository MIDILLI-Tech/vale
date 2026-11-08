<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Roles\Entity\Permission;
use ValeApp\Domain\Roles\Entity\Role;
use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;

final class DbalRoleRepository implements RoleRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function findById(int $id): ?Role
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'roles' . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );

        return $row !== false ? Role::fromRow($row, $this->fetchPermissionIds($id)) : null;
    }

    public function findBySlug(string $slug): ?Role
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'roles' . ' WHERE slug = ?',
            [$slug],
            [ParameterType::STRING],
        );

        if ($row === false) {
            return null;
        }

        return Role::fromRow($row, $this->fetchPermissionIds((int) $row['id']));
    }

    /** @return Role[] */
    public function findAll(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'roles' . ' ORDER BY name ASC',
        );

        if (empty($rows)) {
            return [];
        }

        $roleIds  = array_map(fn (array $row) => (int) $row['id'], $rows);
        $permsMap = $this->batchFetchPermissionIds($roleIds);

        return array_map(
            fn (array $row) => Role::fromRow($row, $permsMap[(int) $row['id']] ?? []),
            $rows,
        );
    }

    /** @return array<int, int[]> roleId => permissionIds[] */
    private function batchFetchPermissionIds(array $roleIds): array
    {
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $rows         = $this->db->fetchAllAssociative(
            'SELECT role_id, permission_id FROM ' . VL_DB_PREFIX . 'role_permissions' . ' WHERE role_id IN (' . $placeholders . ')',
            $roleIds,
            array_fill(0, count($roleIds), ParameterType::INTEGER),
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['role_id']][] = (int) $row['permission_id'];
        }
        return $map;
    }

    /** @return Permission[] */
    public function findAllPermissions(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'permissions' . ' ORDER BY group_name ASC, name ASC',
        );

        return array_map(fn (array $row) => Permission::fromRow($row), $rows);
    }

    public function save(Role $role): Role
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'slug'        => $role->getSlug(),
            'name'        => $role->getName(),
            'description' => $role->getDescription(),
            'is_system'   => (int) $role->isSystem(),
        ];

        if ($role->isNew()) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'roles', $data);
            $role->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'roles', $data, ['id' => $role->getId()]);
        }

        return $role;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'roles', ['id' => $id]);
    }

    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->delete(VL_DB_PREFIX . 'role_permissions', ['role_id' => $roleId]);

        foreach ($permissionIds as $permId) {
            $this->db->insert(VL_DB_PREFIX . 'role_permissions', [
                'role_id'       => $roleId,
                'permission_id' => (int) $permId,
                'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /** @return int[] */
    public function getRoleIdsForUser(int $userId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT role_id FROM ' . VL_DB_PREFIX . 'user_roles' . ' WHERE user_id = ?',
            [$userId],
            [ParameterType::INTEGER],
        );

        return array_column($rows, 'role_id');
    }

    public function assignRoleToUser(int $roleId, int $userId): void
    {
        // Ignore duplicate
        $exists = $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'user_roles' . ' WHERE role_id = ? AND user_id = ?',
            [$roleId, $userId],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        if ((int) $exists === 0) {
            $this->db->insert(VL_DB_PREFIX . 'user_roles', [
                'role_id'    => $roleId,
                'user_id'    => $userId,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function removeRoleFromUser(int $roleId, int $userId): void
    {
        $this->db->delete(VL_DB_PREFIX . 'user_roles', ['role_id' => $roleId, 'user_id' => $userId]);
    }

    /**
     * Returns all permission slugs granted to a user via any of their roles.
     *
     * @return string[]
     */
    public function findPermissionSlugsForUser(int $userId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT DISTINCT p.slug
             FROM ' . VL_DB_PREFIX . 'permissions' . ' p
             INNER JOIN ' . VL_DB_PREFIX . 'role_permissions' . ' rp ON rp.permission_id = p.id
             INNER JOIN ' . VL_DB_PREFIX . 'user_roles' . ' ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ?',
            [$userId],
            [ParameterType::INTEGER],
        );

        return array_column($rows, 'slug');
    }

    /** @return int[] */
    private function fetchPermissionIds(int $roleId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT permission_id FROM ' . VL_DB_PREFIX . 'role_permissions' . ' WHERE role_id = ?',
            [$roleId],
            [ParameterType::INTEGER],
        );

        return array_map(fn (array $r) => (int) $r['permission_id'], $rows);
    }

    private const CPT_PERMISSIONS = ['list', 'create', 'edit', 'delete', 'publish'];

    public function ensureContentTypePermissions(string $slug, string $name): void
    {
        $p   = VL_DB_PREFIX;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach (self::CPT_PERMISSIONS as $action) {
            $permSlug = "cpt.{$slug}.{$action}";
            $permName = "{$name} " . ucfirst($action);

            $exists = $this->db->fetchOne(
                "SELECT COUNT(*) FROM {$p}permissions WHERE slug = ?",
                [$permSlug],
                [ParameterType::STRING],
            );

            if ((int) $exists === 0) {
                $this->db->insert("{$p}permissions", [
                    'slug'       => $permSlug,
                    'name'       => $permName,
                    'group_name' => "cpt_{$slug}",
                    'created_at' => $now,
                ]);
            }
        }

        $permSlugs = array_map(fn ($a) => "cpt.{$slug}.{$a}", self::CPT_PERMISSIONS);

        $roleIds = $this->db->fetchFirstColumn(
            "SELECT id FROM {$p}roles WHERE slug = ? OR slug = ? ORDER BY id",
            ['super_admin', 'admin'],
            [ParameterType::STRING, ParameterType::STRING],
        );
        if (empty($roleIds)) {
            return;
        }

        foreach ($permSlugs as $permSlug) {
            $permId = $this->db->fetchOne(
                "SELECT id FROM {$p}permissions WHERE slug = ?",
                [$permSlug],
                [ParameterType::STRING],
            );
            if ($permId === false) {
                continue;
            }

            foreach ($roleIds as $roleId) {
                $already = $this->db->fetchOne(
                    "SELECT COUNT(*) FROM {$p}role_permissions WHERE role_id = ? AND permission_id = ?",
                    [(int) $roleId, (int) $permId],
                    [ParameterType::INTEGER, ParameterType::INTEGER],
                );

                if ((int) $already === 0) {
                    $this->db->insert("{$p}role_permissions", [
                        'role_id'       => (int) $roleId,
                        'permission_id' => (int) $permId,
                        'created_at'    => $now,
                    ]);
                }
            }
        }
    }

    public function removeContentTypePermissions(string $slug): void
    {
        $p            = VL_DB_PREFIX;
        $permSlugs    = array_map(fn ($a) => "cpt.{$slug}.{$a}", self::CPT_PERMISSIONS);
        $placeholders = implode(',', array_fill(0, count($permSlugs), '?'));
        $types        = array_fill(0, count($permSlugs), ParameterType::STRING);

        $this->db->executeStatement(
            "DELETE FROM {$p}role_permissions"
            . " WHERE permission_id IN (SELECT id FROM {$p}permissions WHERE slug IN ({$placeholders}))",
            $permSlugs,
            $types,
        );

        $this->db->executeStatement(
            "DELETE FROM {$p}permissions WHERE slug IN ({$placeholders})",
            $permSlugs,
            $types,
        );
    }

    public function renameContentTypePermissions(string $oldSlug, string $newSlug): void
    {
        $p   = VL_DB_PREFIX;

        // Update permission slugs and group_name
        $this->db->executeStatement(
            "UPDATE {$p}permissions SET
                slug = REPLACE(slug, ?, ?),
                group_name = REPLACE(group_name, ?, ?)
            WHERE LOWER(slug) LIKE LOWER(?)",
            [
                "cpt.{$oldSlug}.",
                "cpt.{$newSlug}.",
                "cpt_{$oldSlug}",
                "cpt_{$newSlug}",
                "cpt.{$oldSlug}.%",
            ],
            [
                ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }
}
