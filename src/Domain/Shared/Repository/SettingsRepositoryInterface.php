<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\Repository;

interface SettingsRepositoryInterface
{
    public function get(string $key, ?string $default = null): ?string;

    /**
     * Read a boolean setting ('1'/'0' storage convention).
     */
    public function bool(string $key, bool $default = false): bool;

    public function set(
        string $key,
        string $value,
        string $group = 'general',
        string $type = 'string'
    ): void;

    /**
     * Delete every setting whose key starts with the prefix.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByPrefix(string $prefix): int;
}
