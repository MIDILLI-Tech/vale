<?php

declare(strict_types=1);

namespace ValeApp\Domain\User\Repository;

use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\User\Entity\User;

/**
 * User repository contract — implemented in Infrastructure.
 */
interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(Email $email): ?User;

    /**
     * @param array<string, mixed> $filters  optional (e.g. ['role' => 'author'])
     * @return User[]
     */
    public function findAll(?Pagination $pagination = null, array $filters = []): array;

    /**
     * @return User[]  all users without pagination (for name/id maps)
     */
    public function findAllPlain(): array;

    public function count(?array $filters = []): int;

    public function save(User $user): User;

    public function delete(int $id): void;

    public function updateLastLogin(int $id): void;

    /**
     * Bump session_version for every user holding the given role,
     * forcing their sessions to re-authenticate (stale permission snapshot).
     */
    public function incrementSessionVersionForRole(int $roleId): void;

    public function findByVerificationToken(string $token): ?User;

    public function findBySsoProvider(string $provider, string $providerId): ?User;
}
