<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Seo;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Seo\RobotsTxtUseCase;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

final class RobotsTxtUseCaseTest extends TestCase
{
    public function testDefaultRobotsTxtDoesNotContainInvalidLinkDirective(): void
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(static function (string $key, ?string $default = null): ?string {
            return match ($key) {
                'block_ai_crawlers' => '0',
                'robots_txt'        => null,
                default             => $default,
            };
        });

        $output = (new RobotsTxtUseCase($settings, 'https://example.com'))->execute();

        self::assertStringNotContainsString('Link:', $output);
        self::assertStringContainsString('# AI crawler rules: https://example.com/ai-robots.txt', $output);
        self::assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $output);
    }
}
