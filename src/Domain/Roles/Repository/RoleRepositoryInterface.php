<?php

declare(strict_types=1);

namespace ValeApp\Domain\Roles\Repository;

use ValeApp\Domain\Roles\Entity\Permission;
use ValeApp\Domain\Roles\Entity\Role;

interface RoleRepositoryInterface
{
    public function findById(int $id): ?Role;
    public function findBySlug(string $slug): ?Role;

    /** @return Role[] */
    public function findAll(): array;

    /** @return Permission[] */
    public function findAllPermissions(): array;

    public function save(Role $role): Role;
    public function delete(int $id): void;

    /** Replace all permission assignments for a role */
    public function syncPermissions(int $roleId, array $permissionIds): void;

    /**
     * All permission slugs granted to a user via any of their roles.
     *
     * @return string[]
     */
    public function findPermissionSlugsForUser(int $userId): array;

    /** @return int[] role IDs for a given user */
    public function getRoleIdsForUser(int $userId): array;

    /** Assign a role to a user */
    public function assignRoleToUser(int $roleId, int $userId): void;

    /** Remove a role from a user */
    public function removeRoleFromUser(int $roleId, int $userId): void;

    /** Create permissions for a CPT (list, create, edit, delete, publish) */
    public function ensureContentTypePermissions(string $slug, string $name): void;

    /** Remove all permissions for a CPT */
    public function removeContentTypePermissions(string $slug): void;

    /** Rename CPT slug in all related permissions (e.g. cpt.evimevim.* -> cpt.kamil.*) */
    public function renameContentTypePermissions(string $oldSlug, string $newSlug): void;
}
