<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Admin;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Admin\NavOrderService;

final class NavOrderServiceTest extends TestCase
{
    public function testSanitizeOrderKeepsKnownIdsAndDropsEverythingElse(): void
    {
        $order = $this->service()->sanitizeOrder(['users', 'not-a-nav-item', 42, 'dashboard']);

        self::assertSame(['users', 'dashboard'], $order);
    }

    public function testSanitizeOrderKeepsEnabledPluginIds(): void
    {
        $order = $this->service()->sanitizeOrder(
            ['plugin-acme', 'dashboard', 'plugin-disabled'],
            ['plugin-acme'],
        );

        self::assertSame(['plugin-acme', 'dashboard'], $order);
    }

    public function testSanitizeOrderWithoutPluginIdsDropsThem(): void
    {
        $order = $this->service()->sanitizeOrder(['plugin-acme', 'dashboard']);

        self::assertSame(['dashboard'], $order);
    }

    public function testSanitizeChildOrderAllowsPluginPosition(): void
    {
        $order = $this->service()->sanitizeChildOrder(
            ['group_content' => ['pages', 'plugin-acme', 'posts', 'categories', 'tags', 'media']],
            ['plugin-acme' => 'group_content'],
        );

        self::assertSame(
            ['pages', 'plugin-acme', 'posts', 'categories', 'tags', 'media'],
            $order['group_content'],
        );
    }

    public function testSanitizeChildOrderAllowsCoreChildReorderWithinGroup(): void
    {
        $order = $this->service()->sanitizeChildOrder(
            ['group_content' => ['posts', 'pages', 'categories', 'tags', 'media']],
        );

        self::assertSame(
            ['posts', 'pages', 'categories', 'tags', 'media'],
            $order['group_content'],
        );
    }

    public function testSanitizeChildOrderAllowsReviewsAboveComments(): void
    {
        $order = $this->service()->sanitizeChildOrder([
            'group_comments' => ['reviews', 'comments'],
        ]);

        self::assertSame(['reviews', 'comments'], $order['group_comments']);
    }

    public function testSanitizeChildOrderDropsPluginFromAnotherGroup(): void
    {
        $order = $this->service()->sanitizeChildOrder(
            ['group_content' => ['pages', 'plugin-acme', 'posts', 'categories', 'tags', 'media']],
            ['plugin-acme' => 'group_system'],
        );

        self::assertSame(
            ['pages', 'posts', 'categories', 'tags', 'media'],
            $order['group_content'],
        );
    }

    public function testApplyChildOrderPositionsPluginAndKeepsCoreOrder(): void
    {
        $groups = [[
            'id'       => 'group_content',
            'children' => [
                ['id' => 'pages'],
                ['id' => 'posts'],
                ['id' => 'categories'],
                ['id' => 'tags'],
                ['id' => 'media'],
                ['id' => 'plugin-acme'],
            ],
        ]];

        $result = $this->service()->applyChildOrder(
            $groups,
            '{"group_content":["pages","plugin-acme","posts","categories","tags","media"]}',
        );

        self::assertSame(
            ['pages', 'plugin-acme', 'posts', 'categories', 'tags', 'media'],
            array_column($result[0]['children'], 'id'),
        );
    }

    public function testApplyChildOrderAppliesCoreChildReorderWithinGroup(): void
    {
        $groups = [[
            'id'       => 'group_content',
            'children' => [
                ['id' => 'pages'],
                ['id' => 'posts'],
                ['id' => 'categories'],
                ['id' => 'tags'],
                ['id' => 'media'],
                ['id' => 'plugin-acme'],
            ],
        ]];

        $result = $this->service()->applyChildOrder(
            $groups,
            '{"group_content":["posts","pages","categories","tags","media","plugin-acme"]}',
        );

        self::assertSame(
            ['posts', 'pages', 'categories', 'tags', 'media', 'plugin-acme'],
            array_column($result[0]['children'], 'id'),
        );
    }

    private function service(): NavOrderService
    {
        return new NavOrderService();
    }
}
