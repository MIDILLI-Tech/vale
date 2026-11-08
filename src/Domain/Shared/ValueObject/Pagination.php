<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\ValueObject;

/**
 * Represents pagination and sorting parameters.
 */
final readonly class Pagination
{
    public int $offset;

    public function __construct(
        public int $page = 1,
        public int $perPage = 20,
        public ?string $sortBy = null,
        public string $sortDir = 'desc',
    ) {
        $this->offset = ($this->page - 1) * $this->perPage;
    }

    public function totalPages(int $totalItems): int
    {
        return (int) ceil($totalItems / $this->perPage);
    }

    /**
     * Returns a validated ORDER BY clause. $allowed is a whitelist of column names.
     * Falls back to $default if sortBy is null or not in the whitelist.
     * When $prefix is given, sort columns are prefixed with "$prefix." to avoid
     * ambiguous column errors in joined queries (e.g. "id" -> "s.id").
     * The $default should NOT include the prefix in that case.
     */
    public function orderByClause(array $allowed, string $default = 'id DESC', string $prefix = ''): string
    {
        $sortBy = $this->sortBy;
        if ($prefix !== '') {
            $allowed = array_map(fn (string $col) => $prefix . '.' . $col, $allowed);
            if ($sortBy !== null) {
                $sortBy = $prefix . '.' . $sortBy;
            }
        }
        if ($sortBy === null || !in_array($sortBy, $allowed, true)) {
            $col = $prefix !== '' ? $prefix . '.' . $default : $default;
            return ' ORDER BY ' . $col;
        }
        $dir = strtoupper($this->sortDir) === 'ASC' ? 'ASC' : 'DESC';
        return ' ORDER BY ' . $sortBy . ' ' . $dir;
    }
}
