<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

final class DbalProductRepository implements ProductRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findById(int $id): ?Product
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'products' . ' WHERE id = ?', [$id], [ParameterType::INTEGER]);
        if ($row === false) {
            return null;
        }
        return Product::fromRow($row, $this->fetchCategoryIds((int) $row['id']));
    }

    /** @return Product[] */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $ids          = array_map('intval', $ids);
        $ids          = array_filter($ids, static fn (int $id): bool => $id > 0);
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types        = array_fill(0, count($ids), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'products' . " WHERE id IN ($placeholders)",
            array_values($ids),
            $types,
        );
        return $this->hydrateProductRows($rows);
    }

    public function findBySlug(Slug $slug, ?string $language = null): ?Product
    {
        $sql    = 'SELECT * FROM ' . VL_DB_PREFIX . 'products' . ' WHERE slug = ?';
        $params = [(string) $slug];
        $types  = [ParameterType::STRING];

        if ($language !== null) {
            $sql .= ' AND language = ?';
            $params[] = $language;
            $types[]  = ParameterType::STRING;
        }

        $row = $this->db->fetchAssociative($sql, $params, $types);
        if ($row === false) {
            return null;
        }
        return Product::fromRow($row, $this->fetchCategoryIds((int) $row['id']));
    }

    /** @return Product[] */
    public function findBySku(string $sku): ?Product
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'products' . ' WHERE sku = ? ORDER BY id ASC',
            [$sku],
            [ParameterType::STRING],
        );
        if ($row === false) {
            return null;
        }
        return Product::fromRow($row, $this->fetchCategoryIds((int) $row['id']));
    }

    public function findAll(Pagination $pagination, array $filters = []): array
    {
        [$where, $params, $types, $join] = $this->buildWhere($filters);

        $sort = $filters['sort'] ?? null;
        if ($sort === 'name_asc') {
            $orderBy = ' ORDER BY p.name ASC';
        } elseif ($sort === 'name_desc') {
            $orderBy = ' ORDER BY p.name DESC';
        } elseif ($sort === 'price_asc') {
            $orderBy = ' ORDER BY CASE WHEN p.sale_price IS NOT NULL AND p.sale_price < p.price THEN p.sale_price ELSE p.price END ASC, p.id DESC';
        } elseif ($sort === 'price_desc') {
            $orderBy = ' ORDER BY CASE WHEN p.sale_price IS NOT NULL AND p.sale_price < p.price THEN p.sale_price ELSE p.price END DESC, p.id DESC';
        } elseif ($sort === 'newest') {
            $orderBy = ' ORDER BY p.id DESC';
        } elseif ($pagination->sortBy === 'categories') {
            $dir     = strtoupper($pagination->sortDir) === 'ASC' ? 'ASC' : 'DESC';
            $orderBy = ' ORDER BY COALESCE((SELECT MIN(pcat.name) FROM ' . VL_DB_PREFIX . 'product_to_categories' . ' ptc'
                . ' JOIN ' . VL_DB_PREFIX . 'product_categories' . ' pcat ON pcat.id = ptc.category_id'
                . ' WHERE ptc.product_id = p.id), \'\') ' . $dir;
        } else {
            $orderBy = $pagination->orderByClause(
                ['id', 'name', 'sku', 'price', 'sale_price', 'stock_quantity', 'total_sold', 'language', 'status', 'created_at', 'updated_at'],
                'name ASC',
            );
        }

        $sql      = 'SELECT DISTINCT p.* FROM ' . VL_DB_PREFIX . 'products' . ' p' . $join . $where . $orderBy . ' LIMIT ? OFFSET ?';
        $params[] = $pagination->perPage;
        $params[] = $pagination->offset;
        $types[]  = ParameterType::INTEGER;
        $types[]  = ParameterType::INTEGER;
        $rows     = $this->db->fetchAllAssociative($sql, $params, $types);
        return $this->hydrateProductRows($rows);
    }

    public function count(array $filters = []): int
    {
        [$where, $params, $types, $join] = $this->buildWhere($filters);
        return (int) $this->db->fetchOne('SELECT COUNT(DISTINCT p.id) FROM ' . VL_DB_PREFIX . 'products' . ' p' . $join . $where, $params, $types);
    }

    public function save(Product $product): Product
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'name'                       => $product->getName(),
            'language'                   => $product->getLanguage(),
            'translation_of'             => $product->getTranslationOf(),
            'slug'                       => (string) $product->getSlug(),
            'sku'                        => $product->getSku(),
            'description'                => $product->getDescription(),
            'short_description'          => $product->getShortDescription(),
            'price'                      => $product->getPrice(),
            'sale_price'                 => $product->getSalePrice(),
            'sale_price_start'           => $product->getSalePriceStart()?->format('Y-m-d H:i:s'),
            'sale_price_end'             => $product->getSalePriceEnd()?->format('Y-m-d H:i:s'),
            'stock_quantity'             => $product->getStockQuantity(),
            'stock_status'               => $product->getStockStatus(),
            'weight'                     => $product->getWeight(),
            'dimensions'                 => $product->getDimensions(),
            'featured_image_id'          => $product->getFeaturedImageId(),
            'status'                     => $product->getStatus(),
            'published_at'               => $product->getPublishedAt()?->format('Y-m-d H:i:s'),
            'expires_at'                 => $product->getExpiresAt()?->format('Y-m-d H:i:s'),
            'comment_status'             => $product->getCommentStatus(),
            'vote_status'                => $product->getVoteStatus(),
            'type'                       => $product->getType(),
            'shipping_class'             => $product->getShippingClass(),
            'shipping_cost'              => $product->getShippingCost(),
            'shipping_time_min'          => $product->getShippingTimeMin(),
            'shipping_time_max'          => $product->getShippingTimeMax(),
            'shipping_country_overrides' => json_encode($product->getShippingCountryOverrides() ?: new \stdClass()),
            'vat_mode'                   => $product->getVatMode(),
            'vat_rate'                   => $product->getVatRate(),
            'vat_country_overrides'      => json_encode($product->getVatCountryOverrides() ?: new \stdClass()),
            'refund_mode'                => $product->getRefundMode(),
            'download_source'            => $product->getDownloadSource(),
            'download_file_id'           => $product->getDownloadFileId(),
            'download_url'               => $product->getDownloadUrl(),
            'download_url_proxy'         => $product->isDownloadUrlProxy() ? 1 : 0,
            'download_limit'             => $product->getDownloadLimit(),
            'download_expiry_days'       => $product->getDownloadExpiryDays(),
            'meta_title'                 => $product->getMetaTitle(),
            'meta_description'           => $product->getMetaDescription(),

            // GMC fields
            'gtin'                                  => $product->getGtin(),
            'mpn'                                   => $product->getMpn(),
            'brand'                                 => $product->getBrand(),
            'google_product_category'               => $product->getGoogleProductCategory(),
            $this->db->quoteIdentifier('condition') => $product->getCondition(),
            'gender'                                => $product->getGender(),
            'age_group'                             => $product->getAgeGroup(),
            'color'                                 => $product->getColor(),
            'size'                                  => $product->getSize(),
            'material'                              => $product->getMaterial(),
            'pattern'                               => $product->getPattern(),
            'custom_label_0'                        => $product->getCustomLabel0(),
            'custom_label_1'                        => $product->getCustomLabel1(),
            'custom_label_2'                        => $product->getCustomLabel2(),
            'custom_label_3'                        => $product->getCustomLabel3(),
            'custom_label_4'                        => $product->getCustomLabel4(),
            'gmc_exclude'                           => $product->isGmcExclude() ? 1 : 0,

            'upsell_ids'               => json_encode($product->getUpsellIds() ?: new \stdClass()),
            'cross_sell_ids'           => json_encode($product->getCrossSellIds() ?: new \stdClass()),
            'access_tags'              => json_encode($product->getAccessTags() ?: new \stdClass()),
            'length'                   => $product->getLength(),
            'width'                    => $product->getWidth(),
            'height'                   => $product->getHeight(),

            'updated_at'        => $now,
        ];

        if ($product->getId() === null) {
            $data['total_sold'] = 0;
        } else {
            $data['total_sold'] = $product->getTotalSold();
        }

        if ($product->getId() === null) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'products', $data);
            $product->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'products', $data, ['id' => $product->getId()]);
        }

        // Sync categories
        $productId = $product->getId();
        $this->db->delete(VL_DB_PREFIX . 'product_to_categories', ['product_id' => $productId]);
        foreach ($product->getCategoryIds() as $catId) {
            $this->db->insert(VL_DB_PREFIX . 'product_to_categories', ['product_id' => $productId, 'category_id' => $catId]);
        }

        return $product;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'products', ['id' => $id]);
    }

    /** @return Product[] */
    public function findDueForPublishing(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM " . VL_DB_PREFIX . 'products' . " WHERE status = 'scheduled' AND published_at <= ?",
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            [ParameterType::STRING]
        );
        return $this->hydrateProductRows($rows);
    }

    /** @return Product[] */
    public function findDueForExpiry(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM " . VL_DB_PREFIX . 'products' . " WHERE status IN ('published', 'unlisted') AND expires_at IS NOT NULL AND expires_at <= ?",
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            [ParameterType::STRING]
        );
        return $this->hydrateProductRows($rows);
    }

    /** @return Product[] */
    public function findDueForGoPublic(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM " . VL_DB_PREFIX . 'products' . " WHERE status = 'unlisted' AND published_at IS NOT NULL AND published_at <= ?",
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            [ParameterType::STRING]
        );
        return $this->hydrateProductRows($rows);
    }

    /** @return array{average: float, count: int} */
    public function getVoteSummary(int $productId): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT AVG(rating) as average, COUNT(*) as count FROM ' . VL_DB_PREFIX . 'product_votes' . ' WHERE product_id = ?',
            [$productId],
            [ParameterType::INTEGER],
        );
        return [
            'average' => round((float) ($row['average'] ?? 0), 1),
            'count'   => (int) ($row['count'] ?? 0),
        ];
    }

    /** @return int[] */
    private function fetchCategoryIds(int $productId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT category_id FROM ' . VL_DB_PREFIX . 'product_to_categories' . ' WHERE product_id = ?',
            [$productId],
            [ParameterType::INTEGER],
        );
        return array_column($rows, 'category_id');
    }

    /** @param array<array<string,mixed>> $rows @return Product[] */
    private function hydrateProductRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $productIds = array_map(fn (array $r) => (int) $r['id'], $rows);
        $catMap     = $this->batchFetchCategoryIds($productIds);

        return array_map(fn (array $row) => Product::fromRow(
            $row,
            $catMap[(int) $row['id']] ?? [],
        ), $rows);
    }

    /** @return array<int, int[]> productId => categoryId[] */
    private function batchFetchCategoryIds(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types        = array_fill(0, count($productIds), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT product_id, category_id FROM ' . VL_DB_PREFIX . 'product_to_categories'
            . " WHERE product_id IN ($placeholders)",
            $productIds,
            $types,
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['product_id']][] = (int) $row['category_id'];
        }
        return $map;
    }

    /** @return array<int, array<string, mixed>> */
    public function findVariationsByProductId(int $productId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'product_variations' . ' WHERE product_id = ? ORDER BY id ASC',
            [$productId],
            [ParameterType::INTEGER],
        );

        foreach ($rows as &$row) {
            $variationId              = (int) ($row['id'] ?? 0);
            $row['download_file_ids'] = $variationId > 0 ? $this->findVariationDownloadFileIds($variationId) : [];
        }

        return $rows;
    }

    public function findVariationsByProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows         = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'product_variations'
            . " WHERE product_id IN ($placeholders) ORDER BY product_id ASC, id ASC",
            $productIds,
            array_fill(0, count($productIds), ParameterType::INTEGER),
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['product_id']][] = $row;
        }
        return $map;
    }

    public function saveVariations(int $productId, array $variations): void
    {
        // Delete removed variations (keep IDs that are still present)
        $keepIds = array_filter(array_column($variations, 'id'));
        if ($keepIds) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $types        = [ParameterType::INTEGER, ...array_fill(0, count($keepIds), ParameterType::INTEGER)];
            $this->db->executeStatement(
                "DELETE FROM " . VL_DB_PREFIX . "product_variations" . " WHERE product_id = ? AND id NOT IN ($placeholders)",
                array_merge([$productId], $keepIds),
                $types,
            );
        } else {
            $this->db->delete(VL_DB_PREFIX . 'product_variations', ['product_id' => $productId]);
        }

        foreach ($variations as $v) {
            $data = [
                'product_id'                 => $productId,
                'attributes'                 => is_string($v['attributes'] ?? null) ? $v['attributes'] : json_encode($v['attributes'] ?? []),
                'sku'                        => !empty($v['sku']) ? $v['sku'] : null,
                'price'                      => (float) ($v['price'] ?? 0),
                'sale_price'                 => !empty($v['sale_price']) ? (float) $v['sale_price'] : null,
                'sale_price_start'           => !empty($v['sale_price_start']) ? $v['sale_price_start'] : null,
                'sale_price_end'             => !empty($v['sale_price_end']) ? $v['sale_price_end'] : null,
                'stock_quantity'             => (int) ($v['stock_quantity'] ?? 0),
                'stock_status'               => $v['stock_status'] ?? 'in_stock',
                'image_id'                   => !empty($v['image_id']) ? (int) $v['image_id'] : null,
                'download_mode'              => $v['download_mode'] ?? 'use_parent',
                'download_file_id'           => !empty($v['download_file_id']) ? (int) $v['download_file_id'] : null,
                'download_url'               => !empty($v['download_url']) ? $v['download_url'] : null,
                'download_url_proxy'         => !empty($v['download_url_proxy']) ? 1 : 0,
                'download_limit'             => (int) ($v['download_limit'] ?? 0),
                'download_expiry_days'       => (int) ($v['download_expiry_days'] ?? 0),
                'shipping_class'             => $v['shipping_class'] ?? 'use_parent',
                'shipping_cost'              => !empty($v['shipping_cost']) ? (float) $v['shipping_cost'] : null,
                'shipping_time_min'          => !empty($v['shipping_time_min']) ? (int) $v['shipping_time_min'] : null,
                'shipping_time_max'          => !empty($v['shipping_time_max']) ? (int) $v['shipping_time_max'] : null,
                'shipping_country_overrides' => is_string($v['shipping_country_overrides'] ?? null)
                    ? $v['shipping_country_overrides']
                    : json_encode($v['shipping_country_overrides'] ?? new \stdClass()),
                'vat_mode'             => $v['vat_mode'] ?? 'use_parent',
                'vat_rate'             => !empty($v['vat_rate']) ? (float) $v['vat_rate'] : null,
                'refund_mode'          => $v['refund_mode'] ?? 'use_parent',
                'weight'               => !empty($v['weight']) ? (float) $v['weight'] : null,
                'length'               => !empty($v['length']) ? (float) $v['length'] : null,
                'width'                => !empty($v['width']) ? (float) $v['width'] : null,
                'height'               => !empty($v['height']) ? (float) $v['height'] : null,
            ];

            $downloadFileIds = array_values(array_filter(array_map('intval', (array) ($v['download_file_ids'] ?? [])), static fn (int $id): bool => $id > 0));
            if ($downloadFileIds === [] && !empty($v['download_file_id'])) {
                $downloadFileIds = [(int) $v['download_file_id']];
            }
            $downloadFileIds = array_values(array_unique($downloadFileIds));

            if (!empty($v['id'])) {
                $variationId = (int) $v['id'];
                $this->db->update(VL_DB_PREFIX . 'product_variations', $data, ['id' => $variationId]);
                $this->saveVariationDownloadFileIds($variationId, $downloadFileIds);
            } else {
                $this->db->insert(VL_DB_PREFIX . 'product_variations', $data);
                $variationId = (int) $this->db->lastInsertId();
                $this->saveVariationDownloadFileIds($variationId, $downloadFileIds);
            }
        }
    }

    public function deleteVariation(int $variationId): void
    {
        $this->db->delete(VL_DB_PREFIX . 'product_variations', ['id' => $variationId]);
    }

    /** @return int[] */
    public function findVariationDownloadFileIds(int $variationId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT media_id FROM ' . VL_DB_PREFIX . 'product_variation_download_files' . ' WHERE variation_id = ? ORDER BY sort_order ASC, id ASC',
            [$variationId],
            [ParameterType::INTEGER],
        );
        return array_map('intval', array_column($rows, 'media_id'));
    }

    /** @param int[] $mediaIds */
    private function saveVariationDownloadFileIds(int $variationId, array $mediaIds): void
    {
        $this->db->delete(VL_DB_PREFIX . 'product_variation_download_files', ['variation_id' => $variationId]);
        foreach ($mediaIds as $order => $mediaId) {
            $this->db->insert(VL_DB_PREFIX . 'product_variation_download_files', [
                'variation_id' => $variationId,
                'media_id'     => (int) $mediaId,
                'sort_order'   => $order,
                'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function decrementStock(int $productId, int $qty, ?int $variationId = null): void
    {
        if ($variationId !== null) {
            $affected = $this->db->executeStatement(
                "UPDATE " . VL_DB_PREFIX . "product_variations" . " SET stock_quantity = CASE
                    WHEN stock_status = 'on_backorder' THEN stock_quantity - ?
                    WHEN stock_status = 'in_stock' AND stock_quantity > 0 THEN CASE WHEN stock_quantity - ? < 0 THEN 0 ELSE stock_quantity - ? END
                    ELSE stock_quantity END WHERE id = ? AND (stock_status = 'on_backorder' OR stock_quantity > 0)",
                [$qty, $qty, $qty, $variationId],
                [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
            );
            if ($affected > 0) {
                $this->db->executeStatement(
                    "UPDATE " . VL_DB_PREFIX . "product_variations" . " SET stock_status = 'out_of_stock' WHERE id = ? AND stock_quantity <= 0 AND stock_status = 'in_stock'",
                    [$variationId],
                    [ParameterType::INTEGER],
                );
            }
        }

        $affected = $this->db->executeStatement(
            "UPDATE " . VL_DB_PREFIX . 'products' . " SET stock_quantity = CASE
                WHEN stock_status = 'on_backorder' THEN stock_quantity - ?
                WHEN stock_status = 'in_stock' AND stock_quantity > 0 THEN CASE WHEN stock_quantity - ? < 0 THEN 0 ELSE stock_quantity - ? END
                ELSE stock_quantity END WHERE id = ? AND (stock_status = 'on_backorder' OR stock_quantity > 0)",
            [$qty, $qty, $qty, $productId],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        if ($affected > 0) {
            $this->db->executeStatement(
                "UPDATE " . VL_DB_PREFIX . 'products' . " SET stock_status = 'out_of_stock' WHERE id = ? AND stock_quantity <= 0 AND stock_status = 'in_stock'",
                [$productId],
                [ParameterType::INTEGER],
            );
        }
    }

    public function incrementStock(int $productId, int $qty, ?int $variationId = null): void
    {
        if ($variationId !== null) {
            $this->db->executeStatement(
                "UPDATE " . VL_DB_PREFIX . "product_variations" . " SET stock_quantity = stock_quantity + ? WHERE id = ? AND (stock_status != 'in_stock' OR stock_quantity > 0)",
                [$qty, $variationId],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );
            $this->db->executeStatement(
                "UPDATE " . VL_DB_PREFIX . "product_variations" . " SET stock_status = 'in_stock' WHERE id = ? AND stock_quantity > 0 AND stock_status = 'out_of_stock'",
                [$variationId],
                [ParameterType::INTEGER],
            );
        }
        $this->db->executeStatement(
            "UPDATE " . VL_DB_PREFIX . 'products' . " SET stock_quantity = stock_quantity + ? WHERE id = ? AND (stock_status != 'in_stock' OR stock_quantity > 0)",
            [$qty, $productId],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
        $this->db->executeStatement(
            "UPDATE " . VL_DB_PREFIX . 'products' . " SET stock_status = 'in_stock' WHERE id = ? AND stock_quantity > 0 AND stock_status = 'out_of_stock'",
            [$productId],
            [ParameterType::INTEGER],
        );
    }

    public function incrementSold(int $productId, int $qty): void
    {
        $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'products' . ' SET total_sold = total_sold + ? WHERE id = ?',
            [$qty, $productId],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    public function decrementSold(int $productId, int $qty): void
    {
        $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'products' . ' SET total_sold = CASE WHEN total_sold - ? < 0 THEN 0 ELSE total_sold - ? END WHERE id = ?',
            [$qty, $qty, $productId],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /** @return int[] */
    public function findGalleryImageIds(int $productId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT media_id FROM ' . VL_DB_PREFIX . 'product_images' . ' WHERE product_id = ? ORDER BY sort_order ASC',
            [$productId],
            [ParameterType::INTEGER],
        );
        return array_map('intval', array_column($rows, 'media_id'));
    }

    /** @param int[] $mediaIds */
    public function saveGalleryImages(int $productId, array $mediaIds): void
    {
        $this->db->delete(VL_DB_PREFIX . 'product_images', ['product_id' => $productId]);
        foreach ($mediaIds as $order => $mediaId) {
            $this->db->insert(VL_DB_PREFIX . 'product_images', [
                'product_id' => $productId,
                'media_id'   => (int) $mediaId,
                'sort_order' => $order,
            ]);
        }
    }

    /** @return int[] */
    public function findDownloadFileIds(int $productId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT media_id FROM ' . VL_DB_PREFIX . 'product_download_files' . ' WHERE product_id = ? ORDER BY sort_order ASC, id ASC',
            [$productId],
            [ParameterType::INTEGER],
        );
        return array_map('intval', array_column($rows, 'media_id'));
    }

    /** @param int[] $mediaIds */
    public function saveDownloadFileIds(int $productId, array $mediaIds): void
    {
        $this->db->delete(VL_DB_PREFIX . 'product_download_files', ['product_id' => $productId]);
        foreach ($mediaIds as $order => $mediaId) {
            $this->db->insert(VL_DB_PREFIX . 'product_download_files', [
                'product_id' => $productId,
                'media_id'   => (int) $mediaId,
                'sort_order' => $order,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /** @inheritDoc */
    public function findPotentialOriginals(?int $excludeId = null, ?string $language = null): array
    {
        $p      = VL_DB_PREFIX;
        $select = 'SELECT p.*';
        $params = [];
        $types  = [];

        if ($language !== null) {
            $select .= ', CASE WHEN (p.language = ? OR EXISTS (SELECT 1 FROM ' . $p . 'products t'
                . ' WHERE t.translation_of = p.id AND t.language = ?'
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

        $sql = $select . ' FROM ' . $p . 'products p WHERE p.translation_of IS NULL';

        if ($excludeId !== null) {
            $sql     .= ' AND p.id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        $sql .= ' ORDER BY p.name ASC';
        $rows     = $this->db->fetchAllAssociative($sql, $params, $types);
        $products = array_map(fn (array $row) => Product::fromRow($row), $rows);
        $this->hydrateTranslatedLanguages($products, array_map(fn (array $r) => (int) $r['id'], $rows));
        return $products;
    }

    /** @inheritDoc */
    public function findTranslations(int $originalId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'products' . ' WHERE id = ? OR translation_of = ? ORDER BY language ASC',
            [$originalId, $originalId],
            [ParameterType::INTEGER, ParameterType::INTEGER]
        );
        $products = [];
        foreach ($rows as $row) {
            $products[] = Product::fromRow($row, $this->fetchCategoryIds((int) $row['id']));
        }
        return $products;
    }

    /** @return array{0: string, 1: array<mixed>, 2: array<mixed>, 3: string} */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params     = [];
        $types      = [];
        $join       = '';

        if (!empty($filters['category_slug'])) {
            $join     .= ' INNER JOIN ' . VL_DB_PREFIX . 'product_to_categories' . ' pc ON pc.product_id = p.id'
                      . ' INNER JOIN ' . VL_DB_PREFIX . 'product_categories' . ' c ON c.id = pc.category_id AND c.slug = ?';
            $params[]  = $filters['category_slug'];
            $types[]   = ParameterType::STRING;
        }
        if (!empty($filters['category_id'])) {
            $join        .= ' INNER JOIN ' . VL_DB_PREFIX . 'product_to_categories' . ' pc2 ON pc2.product_id = p.id';
            $conditions[] = 'pc2.category_id = ?';
            $params[]     = (int) $filters['category_id'];
            $types[]      = ParameterType::INTEGER;
        }

        if (isset($filters['language'])) {
            if (!empty($filters['language_fallback'])) {
                $conditions[] = '(p.language = ? OR (p.translation_of IS NULL AND NOT EXISTS (
                    SELECT 1 FROM ' . VL_DB_PREFIX . 'products' . ' t2
                    WHERE t2.translation_of = p.id AND t2.language = ?
                )))';
                $params[]     = $filters['language'];
                $params[]     = $filters['language'];
                $types[]      = ParameterType::STRING;
                $types[]      = ParameterType::STRING;
            } else {
                $conditions[] = 'p.language = ?';
                $params[]     = $filters['language'];
                $types[]      = ParameterType::STRING;
            }
        }

        if (isset($filters['translation_of'])) {
            $conditions[] = 'p.translation_of = ?';
            $params[]     = (int) $filters['translation_of'];
            $types[]      = ParameterType::INTEGER;
        }
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(', ', array_fill(0, count($filters['status']), '?'));
                $conditions[] = 'p.status IN (' . $placeholders . ')';
                $params       = array_merge($params, $filters['status']);
                $types        = array_merge($types, array_fill(0, count($filters['status']), ParameterType::STRING));
            } else {
                $conditions[] = 'p.status = ?';
                $params[]     = $filters['status'];
                $types[]      = ParameterType::STRING;
            }
        }
        if (!empty($filters['search'])) {
            $term         = '%' . $filters['search'] . '%';
            $ors          = [
                'LOWER(p.name) LIKE LOWER(?)',
                'LOWER(p.sku) LIKE LOWER(?)',
                'EXISTS (SELECT 1 FROM ' . VL_DB_PREFIX . 'product_to_categories' . ' ptc'
                . ' JOIN ' . VL_DB_PREFIX . 'product_categories' . ' pcat ON pcat.id = ptc.category_id'
                . ' WHERE ptc.product_id = p.id AND LOWER(pcat.name) LIKE LOWER(?))',
            ];
            $params[]     = $term;
            $params[]     = $term;
            $params[]     = $term;
            $types[]      = ParameterType::STRING;
            $types[]      = ParameterType::STRING;
            $types[]      = ParameterType::STRING;
            if (!empty($filters['search_body'])) {
                $ors[]    = 'LOWER(COALESCE(p.description, \'\')) LIKE LOWER(?)';
                $ors[]    = 'LOWER(COALESCE(p.short_description, \'\')) LIKE LOWER(?)';
                $params[] = $term;
                $params[] = $term;
                $types[]  = ParameterType::STRING;
                $types[]  = ParameterType::STRING;
            }
            $conditions[] = '(' . implode(' OR ', $ors) . ')';
        }
        if (!empty($filters['exclude_stock'])) {
            $conditions[] = "p.stock_status != 'out_of_stock'";
        }
        if (!empty($filters['on_sale'])) {
            $now          = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $conditions[] = 'p.sale_price IS NOT NULL AND p.sale_price > 0'
                . ' AND (p.sale_price_start IS NULL OR p.sale_price_start <= ?)'
                . ' AND (p.sale_price_end IS NULL OR p.sale_price_end > ?)';
            $params[]     = $now;
            $params[]     = $now;
            $types[]      = ParameterType::STRING;
            $types[]      = ParameterType::STRING;
        }
        if (!empty($filters['sku'])) {
            $conditions[] = 'p.sku = ?';
            $params[]     = $filters['sku'];
            $types[]      = ParameterType::STRING;
        }
        if (!empty($filters['type'])) {
            if (is_array($filters['type'])) {
                $placeholders = implode(', ', array_fill(0, count($filters['type']), '?'));
                $conditions[] = 'p.type IN (' . $placeholders . ')';
                $params       = array_merge($params, $filters['type']);
                $types        = array_merge($types, array_fill(0, count($filters['type']), ParameterType::STRING));
            } else {
                $conditions[] = 'p.type = ?';
                $params[]     = $filters['type'];
                $types[]      = ParameterType::STRING;
            }
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $params, $types, $join];
    }

    private function hydrateTranslatedLanguages(array $products, array $originalIds): void
    {
        if (empty($originalIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($originalIds), '?'));
        $types        = array_fill(0, count($originalIds), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT translation_of, language FROM ' . VL_DB_PREFIX . 'products WHERE translation_of IN (' . $placeholders . ')',
            $originalIds,
            $types
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['translation_of']][] = (string) $r['language'];
        }
        foreach ($products as $product) {
            $product->setTranslatedLanguages($map[(int) $product->getId()] ?? []);
        }
    }
}
