<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\ProductCategory;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;

final class DbalProductCategoryRepository implements ProductCategoryRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findById(int $id): ?ProductCategory
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'product_categories' . ' WHERE id = ?', [$id], [ParameterType::INTEGER]);
        return $row !== false ? ProductCategory::fromRow($row) : null;
    }

    public function findBySlug(Slug $slug, ?string $language = null): ?ProductCategory
    {
        $sql    = 'SELECT * FROM ' . VL_DB_PREFIX . 'product_categories' . ' WHERE slug = ?';
        $params = [(string) $slug];
        $types  = [ParameterType::STRING];

        if ($language !== null) {
            $sql .= ' AND language = ?';
            $params[] = $language;
            $types[]  = ParameterType::STRING;
        }

        $row = $this->db->fetchAssociative($sql, $params, $types);
        return $row !== false ? ProductCategory::fromRow($row) : null;
    }

    /** @return ProductCategory[] */
    public function findAll(?string $language = null, bool $fallback = false): array
    {
        $table  = VL_DB_PREFIX . 'product_categories';
        $sql    = 'SELECT * FROM ' . $table;
        $params = [];
        $types  = [];
        $where  = [];

        if ($language !== null) {
            if ($fallback) {
                $where[]  = '(language = ? OR (translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . $table . ' t2
                    WHERE t2.translation_of = ' . $table . '.id AND t2.language = ?
                )))';
                $params[] = $language;
                $params[] = $language;
                $types[]  = ParameterType::STRING;
                $types[]  = ParameterType::STRING;
            } else {
                $where[]  = 'language = ?';
                $params[] = $language;
                $types[]  = ParameterType::STRING;
            }
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY sort_order ASC, name ASC';

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        return array_map(fn (array $row) => ProductCategory::fromRow($row), $rows);
    }

    public function save(ProductCategory $category): ProductCategory
    {
        $data = [
            'parent_id'      => $category->getParentId(),
            'name'           => $category->getName(),
            'language'       => $category->getLanguage(),
            'translation_of' => $category->getTranslationOf(),
            'slug'           => (string) $category->getSlug(),
            'description'    => $category->getDescription(),
            'image_id'       => $category->getImageId(),
            'sort_order'     => $category->getSortOrder(),
        ];

        if ($category->getId() === null) {
            $data['created_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->insert(VL_DB_PREFIX . 'product_categories', $data);
            $category->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'product_categories', $data, ['id' => $category->getId()]);
        }

        return $category;
    }

    public function countUsage(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT category_id, COUNT(*) AS cnt FROM ' . VL_DB_PREFIX . 'product_to_categories' . ' GROUP BY category_id',
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['category_id']] = (int) $row['cnt'];
        }
        return $map;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'product_categories', ['id' => $id]);
    }

    /** @inheritDoc */
    public function findPotentialOriginals(?int $excludeId = null, ?string $language = null): array
    {
        $p      = VL_DB_PREFIX;
        $select = 'SELECT c.*';
        $params = [];
        $types  = [];

        if ($language !== null) {
            $select .= ', CASE WHEN (c.language = ? OR EXISTS ('
                . 'SELECT 1 FROM ' . $p . 'product_categories t'
                . ' WHERE t.translation_of = c.id AND t.language = ?'
                . ($excludeId !== null ? ' AND t.id != ?' : '')
                . ')) THEN 1 ELSE 0 END AS is_translated_in_target';
            $params[] = $language;
            $params[] = $language;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
            if ($excludeId !== null) {
                $params[] = $excludeId;
                $types[]  = ParameterType::INTEGER;
            }
        }

        $sql = $select . ' FROM ' . $p . 'product_categories c WHERE c.translation_of IS NULL';

        if ($excludeId !== null) {
            $sql     .= ' AND c.id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        $sql .= ' ORDER BY c.name ASC';
        $rows        = $this->db->fetchAllAssociative($sql, $params, $types);
        $originals   = array_map(fn (array $row) => ProductCategory::fromRow($row), $rows);
        $originalIds = array_map(fn (array $r) => (int) $r['id'], $rows);
        $this->hydrateTranslatedLanguages($originals, $originalIds);

        return $originals;
    }

    /** @inheritDoc */
    public function findTranslations(int $originalId): array
    {
        $sql  = 'SELECT * FROM ' . VL_DB_PREFIX . 'product_categories' . ' WHERE id = ? OR translation_of = ? ORDER BY language ASC';
        $rows = $this->db->fetchAllAssociative($sql, [$originalId, $originalId], [ParameterType::INTEGER, ParameterType::INTEGER]);

        return array_map(fn (array $row) => ProductCategory::fromRow($row), $rows);
    }

    /** @inheritDoc */
    public function findTranslationsForRoots(array $rootIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $rootIds)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'product_categories'
            . ' WHERE id IN (?) OR translation_of IN (?) ORDER BY language ASC',
            [$ids, $ids],
            [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER],
        );

        $grouped = [];
        foreach ($rows as $row) {
            $root             = (int) ($row['translation_of'] ?? $row['id']);
            $grouped[$root][] = ProductCategory::fromRow($row);
        }

        return $grouped;
    }

    /**
     * Bulk-load the languages each original already has a translation in.
     *
     * @param ProductCategory[] $originals
     * @param int[]             $originalIds
     */
    private function hydrateTranslatedLanguages(array $originals, array $originalIds): void
    {
        if (empty($originalIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($originalIds), '?'));
        $types        = array_fill(0, count($originalIds), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT translation_of, language FROM ' . VL_DB_PREFIX . 'product_categories'
            . ' WHERE translation_of IN (' . $placeholders . ')',
            $originalIds,
            $types
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['translation_of']][] = (string) $r['language'];
        }
        foreach ($originals as $original) {
            $original->setTranslatedLanguages($map[(int) $original->getId()] ?? []);
        }
    }
}
