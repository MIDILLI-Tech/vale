<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Content;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Content\MiniMarkdown;

final class MiniMarkdownTest extends TestCase
{
    public function testRendersBoldItalicAndParagraphs(): void
    {
        $html = MiniMarkdown::render("Hello **world**\n\n*second* paragraph");

        self::assertSame('<p>Hello <strong>world</strong></p><p><em>second</em> paragraph</p>', $html);
    }

    public function testRendersOnlyHttpLinks(): void
    {
        $html = MiniMarkdown::render('[site](https://example.com) and [bad](javascript:alert(1))');

        self::assertStringContainsString('<a href="https://example.com" rel="nofollow noopener" target="_blank">site</a>', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringContainsString('[bad]', $html);
    }

    public function testEscapesRawHtml(): void
    {
        $html = MiniMarkdown::render('<script>alert(1)</script> **ok**');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('<strong>ok</strong>', $html);
    }

    public function testEmptyInputRendersNothing(): void
    {
        self::assertSame('', MiniMarkdown::render(null));
        self::assertSame('', MiniMarkdown::render("  \n\n "));
    }
}
