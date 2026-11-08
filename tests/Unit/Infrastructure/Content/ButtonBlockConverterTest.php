<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Content;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Content\ButtonBlockConverter;

final class ButtonBlockConverterTest extends TestCase
{
    // ── WordPress ──────────────────────────────────────────────────────

    public function testConvertWpButtonsGroupVertical(): void
    {
        $input = <<<'HTML'
<p>Before</p>
<!-- wp:buttons -->
<div class="wp-block-buttons is-content-justification-center">
    <!-- wp:button -->
    <div class="wp-block-button">
        <a class="wp-block-button__link" href="https://example.com" style="background-color:#000;color:#fff;border-radius:4px;padding:10px 20px">Click</a>
    </div>
    <!-- /wp:button -->
    <!-- wp:button -->
    <div class="wp-block-button">
        <a class="wp-block-button__link" href="https://example.org" style="background-color:#111;color:#eee;border-radius:8px;padding:12px 24px">Next</a>
    </div>
    <!-- /wp:button -->
</div>
<!-- /wp:buttons -->
<p>After</p>
HTML;

        $result = ButtonBlockConverter::convertWordPress($input);

        self::assertStringContainsString('[link_button_group layout="vertical" gap="12"]', $result);
        self::assertStringContainsString('href="https://example.com"', $result);
        self::assertStringContainsString('bgColor="#000"', $result);
        self::assertStringContainsString('textColor="#fff"', $result);
        // Non-default values are emitted (defaults like 4px / 10px 20px are omitted by design)
        self::assertStringContainsString('borderRadius="8px"', $result);
        self::assertStringContainsString('padding="12px 24px"', $result);
        self::assertStringContainsString('[/link_button_group]', $result);
        self::assertStringContainsString('Before', $result);
        self::assertStringContainsString('After', $result);
        self::assertStringNotContainsString('wp-block-button', $result);
    }

    public function testConvertWpButtonsGroupAlignmentLeft(): void
    {
        $input = <<<'HTML'
<!-- wp:buttons -->
<div class="wp-block-buttons is-content-justification-left">
    <!-- wp:button -->
    <div class="wp-block-button">
        <a class="wp-block-button__link" href="https://example.com">Left</a>
    </div>
    <!-- /wp:button -->
</div>
<!-- /wp:buttons -->
HTML;

        $result = ButtonBlockConverter::convertWordPress($input);

        self::assertStringContainsString('alignment="left"', $result);
        self::assertStringContainsString('href="https://example.com"', $result);
    }

    public function testConvertWpButtonStandalone(): void
    {
        $input = <<<'HTML'
<!-- wp:button -->
<div class="wp-block-button">
    <a class="wp-block-button__link" href="https://example.com">Solo</a>
</div>
<!-- /wp:button -->
HTML;

        $result = ButtonBlockConverter::convertWordPress($input);

        self::assertStringContainsString('[link_button_group', $result);
        self::assertStringContainsString('href="https://example.com"', $result);
        self::assertStringContainsString('Solo', $result);
        self::assertStringNotContainsString('wp:button', $result);
    }

    public function testConvertWpButtonWithBorder(): void
    {
        $input = <<<'HTML'
<!-- wp:button -->
<div class="wp-block-button">
    <a class="wp-block-button__link" href="https://example.com" style="border:2px solid #333;border-radius:6px;padding:8px 16px">Bordered</a>
</div>
<!-- /wp:button -->
HTML;

        $result = ButtonBlockConverter::convertWordPress($input);

        self::assertStringContainsString('borderWidth="2px"', $result);
        self::assertStringContainsString('borderStyle="solid"', $result);
        self::assertStringContainsString('borderColor="#333"', $result);
        self::assertStringContainsString('borderRadius="6px"', $result);
        self::assertStringContainsString('padding="8px 16px"', $result);
    }

    public function testConvertWpNoButtonsLeavesHtmlIntact(): void
    {
        $input = '<p>No buttons here.</p>';

        $result = ButtonBlockConverter::convertWordPress($input);

        self::assertSame($input, $result);
    }

    // ── Ghost ──────────────────────────────────────────────────────────

    public function testConvertGhostButtonCard(): void
    {
        $input = <<<'HTML'
<div class="kg-card kg-button-card">
    <a class="kg-button" href="https://example.com">Ghost Button</a>
</div>
HTML;

        $result = ButtonBlockConverter::convertGhost($input);

        self::assertStringContainsString('[link_button_group layout="vertical" gap="12"]', $result);
        self::assertStringContainsString('href="https://example.com"', $result);
        self::assertStringContainsString('Ghost Button', $result);
        self::assertStringNotContainsString('kg-card', $result);
    }

    public function testConvertGhostNoCardLeavesHtmlIntact(): void
    {
        $input = '<p>No ghost cards here.</p>';

        $result = ButtonBlockConverter::convertGhost($input);

        self::assertSame($input, $result);
    }

    // ── Style parsing edge cases ───────────────────────────────────────

    public function testParseBorderShorthand(): void
    {
        $input  = '<a class="wp-block-button__link" href="https://example.com" style="border:1px dashed #abc">X</a>';
        $result = ButtonBlockConverter::convertWordPress(
            '<!-- wp:button --><div class="wp-block-button">' . $input . '</div><!-- /wp:button -->'
        );

        self::assertStringContainsString('borderWidth="1px"', $result);
        self::assertStringContainsString('borderStyle="dashed"', $result);
        self::assertStringContainsString('borderColor="#abc"', $result);
    }

    public function testConvertWpHtmlEntitiesInText(): void
    {
        $input = <<<'HTML'
<!-- wp:button -->
<div class="wp-block-button">
    <a class="wp-block-button__link" href="https://example.com">Click &amp; Win!</a>
</div>
<!-- /wp:button -->
HTML;

        $result = ButtonBlockConverter::convertWordPress($input);

        self::assertStringContainsString('Click & Win!', $result);
    }
}
