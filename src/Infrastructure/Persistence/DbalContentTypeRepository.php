<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class DbalContentTypeRepository implements ContentTypeRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function findById(int $id): ?ContentType
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'content_types' . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );

        return $row !== false ? ContentType::fromRow($row) : null;
    }

    public function findBySlug(Slug $slug): ?ContentType
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'content_types' . ' WHERE slug = ?',
            [(string) $slug],
            [ParameterType::STRING],
        );

        return $row !== false ? ContentType::fromRow($row) : null;
    }

    /** @inheritDoc */
    public function findAll(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . VL_DB_PREFIX . 'content_types ORDER BY name ASC'
            );

            return array_map(fn (array $row) => ContentType::fromRow($row), $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @inheritDoc */
    public function findCustom(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . VL_DB_PREFIX . 'content_types' . ' WHERE is_system = FALSE ORDER BY name ASC'
            );

            return array_map(fn (array $row) => ContentType::fromRow($row), $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @inheritDoc */
    public function findActiveCustom(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . VL_DB_PREFIX . 'content_types' . ' WHERE is_system = FALSE AND is_active = TRUE ORDER BY name ASC'
            );

            return array_map(fn (array $row) => ContentType::fromRow($row), $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    public function save(ContentType $type): ContentType
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'name'         => $type->getName(),
            'slug'         => (string) $type->getSlug(),
            'description'  => $type->getDescription(),
            'icon'         => $type->getIcon(),
            'supports'     => json_encode($type->getSupports()),
            'field_schema' => json_encode($type->getFieldSchema()),
            'route_prefix' => $type->getRoutePrefix(),
            'is_system'    => (int) $type->isSystem(),
            'is_active'    => (int) $type->isActive(),
        ];

        if ($type->isNew()) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'content_types', $data);
            $type->setId((int) $this->db->lastInsertId());
        } else {
            $data['updated_at'] = $now;
            $this->db->update(VL_DB_PREFIX . 'content_types', $data, ['id' => $type->getId()]);
        }

        return $type;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'content_types', ['id' => $id, 'is_system' => 0]);
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'content_types' . ' WHERE slug = ?';
        $params = [$slug];
        $types  = [ParameterType::STRING];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        return (int) $this->db->fetchOne($sql, $params, $types) > 0;
    }
}
