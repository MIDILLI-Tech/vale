<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shared\ValueObject\Slug;

class SlugService
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function unique(
        string $text,
        string $table,
        string $column = 'slug',
        ?int $excludeId = null,
        ?string $extraWhere = null,
        array $extraParams = [],
    ): Slug {
        $base      = Slug::fromString($text);
        $candidate = $base;
        $suffix    = 1;

        while ($this->exists($candidate, $table, $column, $excludeId, $extraWhere, $extraParams)) {
            $candidate = $base->withSuffix($suffix++);
        }

        return $candidate;
    }

    public function generate(string $text, string $table, string $column = 'slug', ?int $excludeId = null): Slug
    {
        return $this->unique($text, $table, $column, $excludeId);
    }

    private function exists(
        Slug $slug,
        string $table,
        string $column,
        ?int $excludeId,
        ?string $extraWhere,
        array $extraParams,
    ): bool {
        $sql    = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
        $params = [(string) $slug];
        $types  = [ParameterType::STRING];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        if ($extraWhere !== null) {
            $sql .= ' AND ' . $extraWhere;
            $params = array_merge($params, $extraParams);
            $types  = array_merge($types, array_fill(0, count($extraParams), ParameterType::STRING));
        }

        return (int) $this->db->fetchOne($sql, $params, $types) > 0;
    }
}
