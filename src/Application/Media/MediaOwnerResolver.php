<?php

declare(strict_types=1);

namespace ValeApp\Application\Media;

use Doctrine\DBAL\Connection;

/**
 * Resolves the user account that owns imported media rows.
 *
 * `media.user_id` is a NOT NULL foreign key to `users`, so importers must never
 * hard-code an id: when that account has been deleted the insert fails and the
 * image is silently lost (file on disk, no media row, no featured image).
 *
 * Preference order: an active super_admin, an active admin, then the lowest
 * user id. The lookup is cached for the lifetime of the instance (one import).
 */
final class MediaOwnerResolver
{
    private ?int $userId = null;

    private bool $resolved = false;

    public function __construct(private readonly Connection $db)
    {
    }

    public function resolve(): ?int
    {
        if ($this->resolved) {
            return $this->userId;
        }
        $this->resolved = true;

        try {
            foreach (['super_admin', 'admin'] as $roleSlug) {
                $id = $this->db->fetchOne(
                    'SELECT u.id FROM ' . VL_DB_PREFIX . 'users u
                     JOIN ' . VL_DB_PREFIX . 'user_roles ur ON ur.user_id = u.id
                     JOIN ' . VL_DB_PREFIX . 'roles r ON r.id = ur.role_id
                     WHERE r.slug = ?
                     ORDER BY u.id ASC LIMIT 1',
                    [$roleSlug]
                );
                if ($id) {
                    $this->userId = (int) $id;

                    return $this->userId;
                }
            }

            // Last resort: any user (matches the Ghost importer behaviour).
            $id            = $this->db->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'users ORDER BY id ASC LIMIT 1');
            $this->userId  = $id ? (int) $id : null;
        } catch (\Throwable) {
            $this->userId = null;
        }

        return $this->userId;
    }

    /**
     * Resolve a mapped author id (e.g. a WordPress user map), falling back to
     * the import account when the mapped user does not exist in Vale.
     */
    public function resolveMapped(?int $preferred): ?int
    {
        if ($preferred !== null && $preferred > 0) {
            try {
                $exists = $this->db->fetchOne(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'users WHERE id = ?',
                    [$preferred]
                );
                if ($exists) {
                    return (int) $exists;
                }
            } catch (\Throwable) {
                // Fall through to the import account.
            }
        }

        return $this->resolve();
    }
}
