<?php

declare(strict_types=1);

namespace ValeApp\Application\Seo;

use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

final class AiRobotsTxtUseCase
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function execute(): string
    {
        $parts   = [];
        $blockAi = ($this->settings->get('block_ai_crawlers', '0') ?? '0') === '1';

        $parts[] = "# AI Robots Rules for LLMs and AI Agents";

        foreach (AiCrawlerList::all() as $bot) {
            $parts[] = "User-agent: {$bot}";
            if ($blockAi) {
                $parts[] = "Disallow: /";
            } else {
                $parts[] = "Allow: /";
            }
            $parts[] = "";
        }

        return implode("\n", $parts);
    }
}
