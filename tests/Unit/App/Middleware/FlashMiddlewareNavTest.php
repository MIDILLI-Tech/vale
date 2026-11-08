<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\TestCase;
use ValeApp\App\Middleware\FlashMiddleware;

final class FlashMiddlewareNavTest extends TestCase
{
    public function testHidePluginsNavRemovesChildAndEmptiedGroup(): void
    {
        $groups = [
            ['id' => 'dashboard', 'type' => 'link'],
            ['id' => 'group_system', 'type' => 'group', 'children' => [
                ['id' => 'plugins', 'type' => 'link'],
                ['id' => 'themes', 'type' => 'link'],
            ]],
            ['id' => 'group_plugins_only', 'type' => 'group', 'children' => [
                ['id' => 'plugins', 'type' => 'link'],
            ]],
        ];

        $result = FlashMiddleware::hidePluginsNav($groups);

        self::assertSame(['dashboard', 'group_system'], array_column($result, 'id'));
        self::assertSame(['themes'], array_column($result[1]['children'], 'id'));
    }

    public function testHidePluginsNavRemovesTopLevelEntry(): void
    {
        $result = FlashMiddleware::hidePluginsNav([
            ['id' => 'dashboard', 'type' => 'link'],
            ['id' => 'plugins', 'type' => 'link'],
        ]);

        self::assertSame(['dashboard'], array_column($result, 'id'));
    }

    public function testHidePluginNavRemovesOnlyHiddenPluginLinks(): void
    {
        $groups = [
            ['id' => 'plugin-acme', 'type' => 'link'],
            ['id' => 'group_content', 'type' => 'group', 'children' => [
                ['id' => 'pages', 'type' => 'link'],
                ['id' => 'plugin-acme', 'type' => 'link'],
                ['id' => 'plugin-other', 'type' => 'link'],
            ]],
        ];

        $result = FlashMiddleware::hidePluginNav($groups, ['acme']);

        self::assertSame(['group_content'], array_column($result, 'id'));
        self::assertSame(['pages', 'plugin-other'], array_column($result[0]['children'], 'id'));
    }
}
