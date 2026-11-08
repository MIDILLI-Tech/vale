<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Auth;

/**
 * Password hashing service using PHP's native password_hash / password_verify.
 */
final class PasswordHasher
{
    public function __construct(
        private readonly string|int $algo = PASSWORD_BCRYPT,
        private readonly int $cost = 12,
    ) {
    }

    public function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, $this->algo, ['cost' => $this->cost]);
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo, ['cost' => $this->cost]);
    }
}
