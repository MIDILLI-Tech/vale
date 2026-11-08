<?php

declare(strict_types=1);

namespace ValeApp\Application\Seo;

use ValeApp\Domain\Seo\Entity\SeoMeta;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;

class SaveSeoMetaUseCase
{
    public function __construct(
        private readonly SeoMetaRepositoryInterface $seoMeta,
    ) {
    }

    /**
     * Save or update SEO metadata for a given entity.
     *
     * Robots handling: the form posts friendly booleans (allow_index,
     * allow_follow, show_sitemap) which are composed into the stored robots
     * directive here. A legacy raw robots string is still honored when the
     * booleans are absent. Sitemap exclusion is enforced for noindex content
     * (a noindexed URL must never be listed in the sitemap).
     *
     * @param array{
     *     meta_title?: string|null,
     *     meta_description?: string|null,
     *     og_title?: string|null,
     *     og_description?: string|null,
     *     og_image_id?: int|string|null,
     *     twitter_card_type?: string,
     *     canonical_url?: string|null,
     *     robots?: string,
     *     allow_index?: bool|int|string,
     *     allow_follow?: bool|int|string,
     *     show_sitemap?: bool|int|string,
     *     sitemap_exclude?: bool|int|string,
     *     focus_keyword?: string|null,
     * } $data
     */
    public function execute(string $entityType, int $entityId, array $data): SeoMeta
    {
        $seo = $this->seoMeta->findByEntity($entityType, $entityId);

        if ($seo === null) {
            $seo = new SeoMeta($entityType, $entityId);
        }

        $seo->setMetaTitle(self::nullIfEmpty($data['meta_title'] ?? null));
        $seo->setMetaDescription(self::nullIfEmpty($data['meta_description'] ?? null));
        $seo->setOgTitle(self::nullIfEmpty($data['og_title'] ?? null));
        $seo->setOgDescription(self::nullIfEmpty($data['og_description'] ?? null));
        $seo->setCanonicalUrl(self::nullIfEmpty($data['canonical_url'] ?? null));
        $seo->setFocusKeyword(self::nullIfEmpty($data['focus_keyword'] ?? null));
        $seo->setTwitterCardType($data['twitter_card_type'] ?? 'summary');

        $robots = $data['robots'] ?? 'index,follow';
        if (array_key_exists('allow_index', $data) || array_key_exists('allow_follow', $data)) {
            $index  = self::toBool($data['allow_index'] ?? true);
            $follow = self::toBool($data['allow_follow'] ?? true);
            $robots = ($index ? 'index' : 'noindex') . ',' . ($follow ? 'follow' : 'nofollow');
        }
        $seo->setRobots($robots);

        $noindex = str_contains($robots, 'noindex');
        if (array_key_exists('show_sitemap', $data)) {
            $excluded = !self::toBool($data['show_sitemap']);
        } elseif (array_key_exists('sitemap_exclude', $data)) {
            $excluded = self::toBool($data['sitemap_exclude']);
        } else {
            $excluded = $noindex;
        }
        $seo->setSitemapExcluded($excluded || $noindex);

        $ogImageId = $data['og_image_id'] ?? null;
        $seo->setOgImageId($ogImageId !== '' && $ogImageId !== null ? (int) $ogImageId : null);

        return $this->seoMeta->save($seo);
    }

    private static function nullIfEmpty(?string $v): ?string
    {
        return ($v !== null && trim($v) !== '') ? trim($v) : null;
    }

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        return !in_array(strtolower(trim((string) $v)), ['', '0', 'false', 'no', 'off'], true);
    }
}
