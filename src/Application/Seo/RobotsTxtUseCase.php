<?php

declare(strict_types=1);

namespace ValeApp\Application\Seo;

use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

final class RobotsTxtUseCase
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly string $baseUrl,
    ) {
    }

    public function execute(): string
    {
        $parts = [];

        if (($this->settings->get('block_ai_crawlers', '0') ?? '0') === '1') {
            foreach (AiCrawlerList::all() as $bot) {
                $parts[] = "User-agent: {$bot}\nDisallow: /";
            }
        }

        $custom = $this->settings->get('robots_txt');

        if ($custom !== null && trim($custom) !== '') {
            $parts[] = $custom;
        } else {
            $parts[] = "# AI crawler rules: {$this->baseUrl}/ai-robots.txt";

            $parts[] = "User-agent: *\nAllow: /\n\nSitemap: {$this->baseUrl}/sitemap.xml";
        }

        return implode("\n\n", $parts);
    }
}
