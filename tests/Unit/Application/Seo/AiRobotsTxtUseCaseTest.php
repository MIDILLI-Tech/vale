<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Seo;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Seo\AiCrawlerList;
use ValeApp\Application\Seo\AiRobotsTxtUseCase;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

final class AiRobotsTxtUseCaseTest extends TestCase
{
    private function useCase(string $block): AiRobotsTxtUseCase
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static function (string $key, ?string $default = null) use ($block): ?string {
                return match ($key) {
                    'block_ai_crawlers' => $block,
                    default             => $default,
                };
            }
        );

        return new AiRobotsTxtUseCase($settings);
    }

    public function testBlockedOutputDisallowsEveryKnownCrawler(): void
    {
        $output = $this->useCase('1')->execute();

        foreach (AiCrawlerList::all() as $bot) {
            self::assertStringContainsString("User-agent: {$bot}\nDisallow: /", $output);
        }
        self::assertStringNotContainsString('Allow: /', $output);
    }

    public function testAllowedOutputAllowsEveryKnownCrawler(): void
    {
        $output = $this->useCase('0')->execute();

        foreach (AiCrawlerList::all() as $bot) {
            self::assertStringContainsString("User-agent: {$bot}\nAllow: /", $output);
        }
        self::assertStringNotContainsString('Disallow: /', $output);
    }
}
