<?php

declare(strict_types=1);

namespace ValeApp\Application\Seo;

use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

final class GenerateSitemapUseCase
{
    public function __construct(
        private readonly SeoMetaRepositoryInterface $seoMeta,
        private readonly SettingsRepositoryInterface $settings,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Build a complete XML sitemap string.
     */
    public function execute(): string
    {
        $siteUrl        = rtrim($this->baseUrl, '/');
        $blogPrefix     = '/' . ltrim($this->settings->get('routing_blog_prefix', '/blog'), '/');
        $shopPrefix     = '/' . ltrim($this->settings->get('routing_shop_prefix', '/shop'), '/');
        $pagePrefix     = $this->settings->get('routing_page_prefix', '');
        $pagePrefix     = $pagePrefix ? ('/' . ltrim($pagePrefix, '/')) : '';
        $entries        = $this->seoMeta->findIndexableEntries();

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Homepage
        $xml .= "  <url>\n";
        $xml .= "    <loc>{$siteUrl}/</loc>\n";
        $xml .= "    <changefreq>daily</changefreq>\n";
        $xml .= "    <priority>1.0</priority>\n";
        $xml .= "  </url>\n";

        foreach ($entries as $entry) {
            $slug    = htmlspecialchars($entry['slug'], ENT_XML1, 'UTF-8');
            $type    = $entry['entity_type'];
            $lastmod = $entry['updated_at'] ?? null;

            // Build URL based on entity type
            $loc = match ($type) {
                'post'    => "{$siteUrl}{$blogPrefix}/{$slug}",
                'page'    => "{$siteUrl}{$pagePrefix}/{$slug}",
                'product' => "{$siteUrl}{$shopPrefix}/{$slug}",
                default   => "{$siteUrl}/{$type}/{$slug}",
            };

            $xml .= "  <url>\n";
            $xml .= "    <loc>{$loc}</loc>\n";
            if ($lastmod !== null) {
                $xml .= "    <lastmod>" . date('Y-m-d', strtotime($lastmod)) . "</lastmod>\n";
            }
            $priority = $type === 'page' ? '0.8' : '0.6';
            $xml .= "    <priority>{$priority}</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return $xml;
    }
}
