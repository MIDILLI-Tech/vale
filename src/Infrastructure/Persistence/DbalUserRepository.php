<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Security\Encrypter;

/**
 * Doctrine DBAL implementation of UserRepository.
 */
final class DbalUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly Encrypter $encrypter,
    ) {
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'users' . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );

        if ($row === false) {
            return null;
        }

        $row = $this->decryptTwoFactor($row);

        return User::fromRow($row, $this->fetchRoles($id));
    }

    public function findByEmail(Email $email): ?User
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'users' . ' WHERE email = ?',
            [(string) $email],
            [ParameterType::STRING],
        );

        if ($row === false) {
            return null;
        }

        $row = $this->decryptTwoFactor($row);

        return User::fromRow($row, $this->fetchRoles((int) $row['id']));
    }

    /** @return User[] */
    public function findAll(?Pagination $pagination = null, array $filters = []): array
    {
        [$where, $params, $types] = $this->buildWhere($filters);

        if ($pagination !== null) {
            $orderBy = $this->buildOrderBy($pagination);
            $sql     = 'SELECT * FROM ' . VL_DB_PREFIX . 'users' . $where . $orderBy;
            $rows    = $this->db->fetchAllAssociative(
                $sql . ' LIMIT ? OFFSET ?',
                array_merge($params, [$pagination->perPage, $pagination->offset]),
                array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER]),
            );
        } else {
            $rows = $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'users' . $where . ' ORDER BY id ASC', $params, $types);
        }

        if (empty($rows)) {
            return [];
        }

        $userIds  = array_map(fn (array $row) => (int) $row['id'], $rows);
        $rolesMap = $this->batchFetchRoles($userIds);

        return array_map(function (array $row) use ($rolesMap) {
            $row = $this->decryptTwoFactor($row);
            return User::fromRow($row, $rolesMap[(int) $row['id']] ?? []);
        }, $rows);
    }

    /** @return User[]  all users, no pagination */
    public function findAllPlain(): array
    {
        return $this->findAll(null);
    }

    public function count(?array $filters = []): int
    {
        [$where, $params, $types] = $this->buildWhere($filters);

        if ($params) {
            return (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'users' . $where,
                $params,
                $types,
            );
        }

        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'users');
    }

    /**
     * Search also resolves the displayed Role column (slugs and human names) —
     * client-side mode matched it via the rendered badge, so this keeps
     * server-side results identical on large user bases.
     *
     * @return array{0: string, 1: array<mixed>, 2: array<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];
        $types   = [];

        if (!empty($filters['role'])) {
            $clauses[] = 'EXISTS (SELECT 1 FROM ' . VL_DB_PREFIX . 'user_roles' . ' ur INNER JOIN ' . VL_DB_PREFIX . 'roles' . ' r ON ur.role_id = r.id WHERE ur.user_id = ' . VL_DB_PREFIX . 'users' . '.id AND r.slug = ?)';
            $params[]  = $filters['role'];
            $types[]   = ParameterType::STRING;
        }

        if (!empty($filters['search'])) {
            $term      = '%' . trim((string) $filters['search']) . '%';
            $clauses[] = '(LOWER(' . VL_DB_PREFIX . 'users' . '.name) LIKE LOWER(?)'
                . ' OR LOWER(' . VL_DB_PREFIX . 'users' . '.email) LIKE LOWER(?)'
                . ' OR EXISTS (SELECT 1 FROM ' . VL_DB_PREFIX . 'user_roles' . ' ur2'
                . ' INNER JOIN ' . VL_DB_PREFIX . 'roles' . ' r2 ON ur2.role_id = r2.id'
                . ' WHERE ur2.user_id = ' . VL_DB_PREFIX . 'users' . '.id'
                . ' AND (LOWER(r2.slug) LIKE LOWER(?) OR LOWER(r2.name) LIKE LOWER(?))))';
            $params[]  = $term;
            $params[]  = $term;
            $params[]  = $term;
            $params[]  = $term;
            $types[]   = ParameterType::STRING;
            $types[]   = ParameterType::STRING;
            $types[]   = ParameterType::STRING;
            $types[]   = ParameterType::STRING;
        }

        $where = $clauses ? ' WHERE ' . implode(' AND ', $clauses) : '';
        return [$where, $params, $types];
    }

    /**
     * Role sorting resolves to the lexicographically first role slug — the
     * badge shows roles[0], and the role table can hold many rows per user.
     */
    private function buildOrderBy(Pagination $pagination): string
    {
        if ($pagination->sortBy === 'role') {
            $dir = strtoupper($pagination->sortDir) === 'ASC' ? 'ASC' : 'DESC';
            return ' ORDER BY COALESCE((SELECT MIN(r2.slug) FROM ' . VL_DB_PREFIX . 'user_roles' . ' ur2'
                . ' INNER JOIN ' . VL_DB_PREFIX . 'roles' . ' r2 ON ur2.role_id = r2.id'
                . ' WHERE ur2.user_id = ' . VL_DB_PREFIX . 'users' . '.id), \'\') ' . $dir . ', id DESC';
        }
        return $pagination->orderByClause(
            ['id', 'name', 'email', 'is_active', 'created_at', 'last_login_at'],
            'id DESC',
        );
    }

    /** @return array<int, string[]> userId => roleSlugs[] */
    private function batchFetchRoles(array $userIds): array
    {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows         = $this->db->fetchAllAssociative(
            'SELECT ur.user_id, r.slug FROM ' . VL_DB_PREFIX . 'roles' . ' r '
            . 'INNER JOIN ' . VL_DB_PREFIX . 'user_roles' . ' ur ON ur.role_id = r.id '
            . 'WHERE ur.user_id IN (' . $placeholders . ')',
            $userIds,
            array_fill(0, count($userIds), ParameterType::INTEGER),
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['user_id']][] = $row['slug'];
        }
        return $map;
    }

    public function save(User $user): User
    {
        $data = [
            'email'                          => (string) $user->getEmail(),
            'password'                       => $user->getPassword(),
            'name'                           => $user->getName(),
            'avatar'                         => $user->getAvatar(),
            'locale'                         => $user->getLocale(),
            'is_active'                      => $user->isActive() ? 1 : 0,
            'email_verified_at'              => $user->getEmailVerifiedAt()?->format('Y-m-d H:i:s'),
            'verification_token'             => $user->getVerificationToken(),
            'verification_token_expires_at'  => $user->getVerificationTokenExpiresAt()?->format('Y-m-d H:i:s'),
            'last_login_at'                  => $user->getLastLoginAt()?->format('Y-m-d H:i:s'),
            'sso_provider'                   => $user->getSsoProvider(),
            'sso_provider_id'                => $user->getSsoProviderId(),
            'two_factor_secret'              => $user->getTwoFactorSecret() ? $this->encrypter->encrypt($user->getTwoFactorSecret()) : null,
            'two_factor_recovery_codes'      => $this->encrypter->encrypt(json_encode($user->getTwoFactorRecoveryCodes())),
            'two_factor_enabled'             => $user->isTwoFactorEnabled() ? 1 : 0,
            'totp_last_slice'                => $user->getTotpLastSlice(),
            'session_version'                => $user->getSessionVersion(),
        ];

        if ($user->isNew()) {
            $data['created_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->insert(VL_DB_PREFIX . 'users', $data);
            $user->setId((int) $this->db->lastInsertId());
        } else {
            $data['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->update(VL_DB_PREFIX . 'users', $data, ['id' => $user->getId()]);
        }

        // Sync roles
        $this->syncRoles($user->getId(), $user->getRoles());

        return $user;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'users', ['id' => $id]);
    }

    public function findByVerificationToken(string $token): ?User
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'users' . ' WHERE verification_token = ? AND (verification_token_expires_at IS NULL OR verification_token_expires_at > ?)',
            [$token, (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            [ParameterType::STRING, ParameterType::STRING],
        );

        if ($row === false) {
            return null;
        }

        $row = $this->decryptTwoFactor($row);

        return User::fromRow($row, $this->fetchRoles((int) $row['id']));
    }

    public function findBySsoProvider(string $provider, string $providerId): ?User
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'users' . ' WHERE sso_provider = ? AND sso_provider_id = ?',
            [$provider, $providerId],
            [ParameterType::STRING, ParameterType::STRING],
        );

        if ($row === false) {
            return null;
        }

        $row = $this->decryptTwoFactor($row);

        return User::fromRow($row, $this->fetchRoles((int) $row['id']));
    }

    private function decryptTwoFactor(array $row): array
    {
        if (!empty($row['two_factor_secret'])) {
            $row['two_factor_secret'] = $this->encrypter->decrypt($row['two_factor_secret']);
        }
        if (!empty($row['two_factor_recovery_codes'])) {
            $row['two_factor_recovery_codes'] = $this->encrypter->decrypt($row['two_factor_recovery_codes']);
        }
        return $row;
    }

    public function updateLastLogin(int $id): void
    {
        $this->db->update(VL_DB_PREFIX . 'users', [
            'last_login_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function incrementSessionVersionForRole(int $roleId): void
    {
        $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'users'
            . ' SET session_version = session_version + 1, updated_at = ?'
            . ' WHERE id IN (SELECT user_id FROM ' . VL_DB_PREFIX . 'user_roles WHERE role_id = ?)',
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $roleId],
            [ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    /** @return string[] Role slugs */
    private function fetchRoles(int $userId): array
    {
        return $this->db->fetchFirstColumn(
            'SELECT r.slug FROM ' . VL_DB_PREFIX . 'roles' . ' r '
            . 'INNER JOIN ' . VL_DB_PREFIX . 'user_roles' . ' ur ON ur.role_id = r.id '
            . 'WHERE ur.user_id = ?',
            [$userId],
            [ParameterType::INTEGER],
        );
    }

    /** @param string[] $roleSlugs */
    private function syncRoles(int $userId, array $roleSlugs): void
    {
        if (empty($roleSlugs)) {
            return;
        }

        // Delete existing role assignments
        $this->db->delete(VL_DB_PREFIX . 'user_roles', ['user_id' => $userId]);

        // Insert new role assignments
        foreach ($roleSlugs as $slug) {
            $roleId = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'roles' . ' WHERE slug = ?',
                [$slug],
                [ParameterType::STRING],
            );

            if ($roleId !== false) {
                $this->db->insert(VL_DB_PREFIX . 'user_roles', [
                    'user_id'    => $userId,
                    'role_id'    => (int) $roleId,
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}
