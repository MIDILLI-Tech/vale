<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;

final class DbalMediaRepository implements MediaRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function findById(int $id): ?Media
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'media' . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );

        return $row !== false ? Media::fromRow($row) : null;
    }

    public function findByPath(string $path): ?Media
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'media' . ' WHERE path = ?',
            [$path],
            [ParameterType::STRING],
        );

        return $row !== false ? Media::fromRow($row) : null;
    }

    public function findByContentHash(string $hash): ?Media
    {
        if ($hash === '') {
            return null;
        }

        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'media' . ' WHERE content_hash = ?',
            [$hash],
            [ParameterType::STRING],
        );

        return $row !== false ? Media::fromRow($row) : null;
    }

    public function findByOriginalName(string $originalName): array
    {
        // Newest record first: callers (replace-mode uploads) pick the first
        // entry when several uploads share the same original name, and the
        // result must be deterministic across database engines.
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'media' . ' WHERE original_name = ? ORDER BY id DESC',
            [$originalName],
            [ParameterType::STRING],
        );

        return array_map(fn (array $row) => Media::fromRow($row), $rows);
    }

    /** @inheritDoc */
    public function findAll(Pagination $pagination, array $filters = []): array
    {
        [$where, $params, $types] = $this->buildWhere($filters);

        $orderBy = $pagination->orderByClause(
            ['id', 'original_name', 'mime_type', 'size', 'created_at'],
            'id DESC',
        );
        $sql = 'SELECT * FROM ' . VL_DB_PREFIX . 'media' . $where
             . $orderBy . ' LIMIT ? OFFSET ?';
        $params[] = $pagination->perPage;
        $params[] = $pagination->offset;
        $types[]  = ParameterType::INTEGER;
        $types[]  = ParameterType::INTEGER;

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        return array_map(fn (array $row) => Media::fromRow($row), $rows);
    }

    /** @inheritDoc */
    public function count(array $filters = []): int
    {
        [$where, $params, $types] = $this->buildWhere($filters);
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'media' . $where,
            $params,
            $types,
        );
    }

    public function save(Media $media): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'user_id'          => $media->getUserId(),
            'filename'         => $media->getFilename(),
            'original_name'    => $media->getOriginalName(),
            'mime_type'        => $media->getMimeType(),
            'size'             => $media->getSize(),
            'path'             => $media->getPath(),
            'disk'             => $media->getDisk(),
            'alt_text'         => $media->getAltText(),
            'title'            => $media->getTitle(),
            'description'      => $media->getDescription(),
            'alt_text_i18n'    => $this->encodeI18nMap($media->getAltTextI18n()),
            'description_i18n' => $this->encodeI18nMap($media->getDescriptionI18n()),
            'thumbnails'       => json_encode($media->getThumbnails()),
            'content_hash'     => $media->getContentHash() !== '' ? $media->getContentHash() : null,
        ];

        if ($media->isNew()) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'media', $data);
            $id = (int) $this->db->lastInsertId();
            $media->setId($id);
            return $id;
        }

        $data['updated_at'] = $now;
        $this->db->update(VL_DB_PREFIX . 'media', $data, ['id' => $media->getId()]);
        return $media->getId();
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'media', ['id' => $id]);
    }

    public function getTotalSizeByUser(int $userId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COALESCE(SUM(size), 0) FROM ' . VL_DB_PREFIX . 'media' . ' WHERE user_id = ?',
            [$userId],
            [ParameterType::INTEGER],
        );
    }

    public function findMissingFiles(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, path, original_name, size, mime_type FROM ' . VL_DB_PREFIX . 'media',
        );
        $uploadDir = rtrim(base_path('storage/uploads'), '/');
        $missing   = [];
        foreach ($rows as $row) {
            if (!is_file($uploadDir . '/' . $row['path'])) {
                $missing[(int) $row['id']] = [
                    'id'            => (int) $row['id'],
                    'path'          => $row['path'],
                    'original_name' => $row['original_name'],
                    'size'          => (int) $row['size'],
                    'mime_type'     => $row['mime_type'],
                ];
            }
        }
        return $missing;
    }

    public function findUnused(): array
    {
        $prefix = VL_DB_PREFIX;

        // Media not referenced by any direct ID reference / FK
        $rows = $this->db->fetchAllAssociative(
            "SELECT m.id, m.path, m.original_name, m.size, m.mime_type, m.thumbnails
            FROM {$prefix}media m
            LEFT JOIN {$prefix}posts p ON p.featured_image_id = m.id
            LEFT JOIN {$prefix}products pr ON pr.featured_image_id = m.id
            LEFT JOIN {$prefix}product_images pi ON pi.media_id = m.id
            LEFT JOIN {$prefix}product_download_files pdf ON pdf.media_id = m.id
            LEFT JOIN {$prefix}product_variation_download_files pvdf ON pvdf.media_id = m.id
            LEFT JOIN {$prefix}product_variations pv ON pv.image_id = m.id
            LEFT JOIN {$prefix}product_categories pc ON pc.image_id = m.id
            LEFT JOIN {$prefix}seo_meta sm ON sm.og_image_id = m.id
            WHERE p.id IS NULL AND pr.id IS NULL AND pi.id IS NULL
              AND pdf.id IS NULL AND pvdf.id IS NULL AND pv.id IS NULL
              AND pc.id IS NULL AND sm.id IS NULL"
        );

        if ($rows === []) {
            return [];
        }

        $unused = [];
        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $path = (string) $row['path'];

            // Editor blocks (inline <img>, media+text, carousel, …) reference
            // media by URL string rather than by media id, so a record counts
            // as used when any of its real files appears in content: the main
            // file, every recorded rendition (small/medium/large/webp/…), plus
            // legacy <base>-<size>.<ext> names in both the original and webp
            // formats (rows whose thumbnails predate tracking).
            $searchPaths = [$path];

            $thumbnails = json_decode((string) ($row['thumbnails'] ?? ''), true);
            if (is_array($thumbnails)) {
                foreach ($thumbnails as $thumbRel) {
                    if (is_string($thumbRel) && $thumbRel !== '') {
                        $searchPaths[] = str_replace('\\', '/', $thumbRel);
                    }
                }
            }

            $pi = pathinfo($path);
            if (!empty($pi['extension']) && !empty($pi['filename'])) {
                $pDir = $pi['dirname'] ?? '.';
                $dir  = $pDir                   === '.' ? '' : str_replace('\\', '/', $pDir);
                $dir  = $dir                    === '' ? '' : $dir . '/';
                $fn   = $pi['filename'];
                $ext  = $pi['extension'];
                foreach (['small', 'medium', 'large'] as $size) {
                    $searchPaths[] = $dir . $fn . '-' . $size . '.' . $ext;
                    $searchPaths[] = $dir . $fn . '-' . $size . '.webp';
                }
            }

            $searchPaths = array_values(array_unique($searchPaths));

            $buildMultiLike = function (array $cols, array $paths): array {
                $parts  = [];
                $params = [];
                foreach ($paths as $sp) {
                    $like = '%' . $sp . '%';
                    foreach ($cols as $col) {
                        $parts[]  = "LOWER($col) LIKE LOWER(?)";
                        $params[] = $like;
                    }
                }
                return [implode(' OR ', $parts), $params];
            };

            foreach (
                [
                    [$prefix . 'posts', ['body']],
                    [$prefix . 'products', ['description', 'short_description']],
                    [$prefix . 'newsletter_campaigns', ['body_html', 'body_html_b']],
                    [$prefix . 'automation_steps', ['body_html', 'body_html_b']],
                    [$prefix . 'categories', ['description']],
                    [$prefix . 'product_categories', ['description']],
                ] as [$table, $cols]
            ) {
                [$sqlCols, $sqlParams] = $buildMultiLike($cols, $searchPaths);
                $usedInContent         = (int) $this->db->fetchOne(
                    "SELECT COUNT(*) FROM {$table} WHERE {$sqlCols}",
                    $sqlParams
                );
                if ($usedInContent > 0) {
                    continue 2;
                }
            }

            $unused[$id] = [
                'id'            => $id,
                'path'          => $path,
                'original_name' => $row['original_name'],
                'size'          => (int) $row['size'],
                'mime_type'     => $row['mime_type'],
            ];
        }

        return $unused;
    }

    public function findReferencedIds(): array
    {
        $p    = VL_DB_PREFIX;
        $rows = $this->db->fetchAllAssociative(
            "SELECT featured_image_id AS mid FROM {$p}posts WHERE featured_image_id IS NOT NULL
             UNION
             SELECT featured_image_id FROM {$p}products WHERE featured_image_id IS NOT NULL
             UNION
             SELECT media_id FROM {$p}product_images WHERE media_id IS NOT NULL
             UNION
             SELECT media_id FROM {$p}product_download_files WHERE media_id IS NOT NULL
             UNION
             SELECT media_id FROM {$p}product_variation_download_files WHERE media_id IS NOT NULL
             UNION
             SELECT image_id FROM {$p}product_variations WHERE image_id IS NOT NULL
             UNION
             SELECT image_id FROM {$p}product_categories WHERE image_id IS NOT NULL
             UNION
             SELECT og_image_id FROM {$p}seo_meta WHERE og_image_id IS NOT NULL",
        );

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['mid']] = true;
        }
        return $ids;
    }

    /**
     * @param array{mime_type?: string, user_id?: int, search?: string, ids?: int[]} $filters
     * @return array{string, array<mixed>, array<ParameterType>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];
        $types   = [];

        if (!empty($filters['mime_type'])) {
            // Comma-separated prefixes (e.g. "image/,video/") match any of
            // them; a single prefix keeps the previous exact behavior.
            $prefixes = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $filters['mime_type'])
            )));
            if (count($prefixes) === 1) {
                $clauses[] = 'LOWER(mime_type) LIKE LOWER(?)';
                $params[]  = $prefixes[0] . '%';
                $types[]   = ParameterType::STRING;
            } elseif ($prefixes !== []) {
                $ors = [];
                foreach ($prefixes as $prefix) {
                    $ors[]    = 'LOWER(mime_type) LIKE LOWER(?)';
                    $params[] = $prefix . '%';
                    $types[]  = ParameterType::STRING;
                }
                $clauses[] = '(' . implode(' OR ', $ors) . ')';
            }
        }

        if (!empty($filters['user_id'])) {
            $clauses[] = 'user_id = ?';
            $params[]  = (int) $filters['user_id'];
            $types[]   = ParameterType::INTEGER;
        }

        if (!empty($filters['search'])) {
            $clauses[] = '(LOWER(original_name) LIKE LOWER(?)'
                . ' OR LOWER(title) LIKE LOWER(?)'
                . ' OR LOWER(alt_text) LIKE LOWER(?)'
                . ' OR LOWER(description) LIKE LOWER(?)'
                . ' OR LOWER(alt_text_i18n) LIKE LOWER(?)'
                . ' OR LOWER(description_i18n) LIKE LOWER(?))';
            $term      = '%' . $filters['search'] . '%';
            $params[]  = $term;
            $params[]  = $term;
            $params[]  = $term;
            $params[]  = $term;
            $params[]  = $term;
            $params[]  = $term;
            $types[]   = ParameterType::STRING;
            $types[]   = ParameterType::STRING;
            $types[]   = ParameterType::STRING;
            $types[]   = ParameterType::STRING;
            $types[]   = ParameterType::STRING;
            $types[]   = ParameterType::STRING;
        }

        if (!empty($filters['ids'])) {
            $ids          = array_map('intval', $filters['ids']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $clauses[]    = 'id IN (' . $placeholders . ')';
            foreach ($ids as $id) {
                $params[] = $id;
                $types[]  = ParameterType::INTEGER;
            }
        }

        $where = $clauses ? ' WHERE ' . implode(' AND ', $clauses) : '';
        return [$where, $params, $types];
    }

    /** @param array<string, string> $map */
    private function encodeI18nMap(array $map): ?string
    {
        if ($map === []) {
            return null;
        }

        return json_encode($map, JSON_UNESCAPED_UNICODE);
    }
}
