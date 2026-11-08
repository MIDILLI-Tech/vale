<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Seo\Entity\SeoMeta;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;

final class DbalSeoMetaRepository implements SeoMetaRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function findByEntity(string $entityType, int $entityId): ?SeoMeta
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'seo_meta' . ' WHERE entity_type = ? AND entity_id = ?',
            [$entityType, $entityId],
            [ParameterType::STRING, ParameterType::INTEGER],
        );

        return $row !== false ? SeoMeta::fromRow($row) : null;
    }

    public function save(SeoMeta $seo): SeoMeta
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'entity_type'       => $seo->getEntityType(),
            'entity_id'         => $seo->getEntityId(),
            'meta_title'        => $seo->getMetaTitle(),
            'meta_description'  => $seo->getMetaDescription(),
            'og_title'          => $seo->getOgTitle(),
            'og_description'    => $seo->getOgDescription(),
            'og_image_id'       => $seo->getOgImageId(),
            'twitter_card_type' => $seo->getTwitterCardType(),
            'canonical_url'     => $seo->getCanonicalUrl(),
            'robots'            => $seo->getRobots(),
            'sitemap_exclude'   => $seo->isSitemapExcluded() ? 1 : 0,
            'focus_keyword'     => $seo->getFocusKeyword(),
        ];

        if ($seo->isNew()) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'seo_meta', $data);
            $seo->setId((int) $this->db->lastInsertId());
        } else {
            $data['updated_at'] = $now;
            $this->db->update(VL_DB_PREFIX . 'seo_meta', $data, ['id' => $seo->getId()]);
        }

        return $seo;
    }

    public function deleteByEntity(string $entityType, int $entityId): void
    {
        $this->db->delete(VL_DB_PREFIX . 'seo_meta', [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
        ]);
    }

    /** @inheritDoc */
    public function findIndexableEntries(): array
    {
        // Fetch published posts/pages for sitemap generation.
        // Left join seo_meta so content without explicit SEO entries is still included (defaults to index).
        // Sitemap exclusion is authoritative via sm.sitemap_exclude; the robots
        // noindex filter is kept as a backstop so a noindexed URL can never
        // leak into the sitemap even if the flag ever desyncs.
        $p   = VL_DB_PREFIX;
        $sql = <<<SQL
            SELECT
                p.slug,
                COALESCE(p.updated_at, p.created_at) AS updated_at,
                p.content_type AS entity_type,
                p.id AS entity_id
            FROM {$p}posts p
            LEFT JOIN {$p}seo_meta sm ON sm.entity_type = p.content_type AND sm.entity_id = p.id
            WHERE p.status = 'published'
              AND (sm.sitemap_exclude IS NULL OR sm.sitemap_exclude = FALSE)
              AND (sm.robots IS NULL OR sm.robots NOT LIKE '%noindex%')
            ORDER BY p.updated_at DESC
        SQL;

        $entries = $this->db->fetchAllAssociative($sql);

        // Also include published products
        $productSql = <<<SQL
            SELECT
                pr.slug,
                COALESCE(pr.updated_at, pr.created_at) AS updated_at,
                'product' AS entity_type,
                pr.id AS entity_id
            FROM {$p}products pr
            LEFT JOIN {$p}seo_meta sm ON sm.entity_type = 'product' AND sm.entity_id = pr.id
            WHERE pr.status = 'published'
              AND (sm.sitemap_exclude IS NULL OR sm.sitemap_exclude = FALSE)
              AND (sm.robots IS NULL OR sm.robots NOT LIKE '%noindex%')
            ORDER BY pr.updated_at DESC
        SQL;

        $products = $this->db->fetchAllAssociative($productSql);

        return array_merge($entries, $products);
    }
}
