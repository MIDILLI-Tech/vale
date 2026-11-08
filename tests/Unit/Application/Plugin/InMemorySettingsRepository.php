<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Plugin;

use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

/**
 * Minimal in-memory settings store used by the plugin tests.
 */
final class InMemorySettingsRepository implements SettingsRepositoryInterface
{
    /** @var array<string, string> */
    public array $values;

    public int $writes = 0;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        return $value === null ? $default : $value === '1';
    }

    public function set(
        string $key,
        string $value,
        string $group = 'general',
        string $type = 'string',
    ): void {
        $this->values[$key] = $value;
        $this->writes++;
    }

    public function deleteByPrefix(string $prefix): int
    {
        $deleted = 0;

        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->values[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }
}
