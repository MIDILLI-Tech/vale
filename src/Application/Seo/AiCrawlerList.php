<?php

declare(strict_types=1);

namespace ValeApp\Application\Seo;

final class AiCrawlerList
{
    /** @var list<string> */
    private const CRAWLERS = [
        'Amazonbot',
        'anthropic-ai',
        'Applebot',
        'Applebot-Extended',
        'Bytespider',
        'CCBot',
        'ChatGPT-Agent',
        'ChatGPT-Apple-Preview',
        'ChatGPT-User',
        'Claude-Searchbot',
        'Claude-User',
        'Claude-Web',
        'ClaudeBot',
        'cohere-ai',
        'cohere-training-data-crawler',
        'DuckAssistBot',
        'Diffbot',
        'FacebookBot',
        'Google-CloudVertexBot',
        'Google-Extended',
        'Google-InspectionTool',
        'GoogleOther',
        'GPTBot',
        'ImagesiftBot',
        'Meta-ExternalAgent',
        'Meta-ExternalFetcher',
        'Meta-LlamaBot',
        'MistralAI-User',
        'OAI-SearchBot',
        'OmgiliBot',
        'Perplexity-User',
        'PerplexityBot',
        'YouBot',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::CRAWLERS;
    }
}
