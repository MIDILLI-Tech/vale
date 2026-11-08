<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Content;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Content\DiffHelper;

final class DiffHelperTest extends TestCase
{
    public function testIdenticalStringsProduceOnlySameLines(): void
    {
        $lines = DiffHelper::diff("alpha\nbeta", "alpha\nbeta");

        self::assertCount(2, $lines);
        self::assertSame(['same', 'same'], array_column($lines, 'type'));
        self::assertFalse(DiffHelper::hasChanges($lines));
    }

    public function testInsertionDoesNotFlagFollowingLinesAsChanged(): void
    {
        $lines = DiffHelper::diff("alpha\nbravo\ncharlie", "alpha\nxray\nbravo\ncharlie");

        self::assertSame(['same', 'add', 'same', 'same'], array_column($lines, 'type'));

        $added = array_values(array_filter($lines, fn (array $l) => $l['type'] === 'add'));
        self::assertCount(1, $added);
        self::assertSame('xray', $added[0]['new']);
    }

    public function testDeletionDetectedAtCorrectPosition(): void
    {
        $lines = DiffHelper::diff("alpha\nbravo\ncharlie", "alpha\ncharlie");

        self::assertSame(['same', 'del', 'same'], array_column($lines, 'type'));
        self::assertSame('bravo', $lines[1]['old']);
        self::assertTrue(DiffHelper::hasChanges($lines));
    }

    public function testModifiedLineBecomesChangePair(): void
    {
        $lines = DiffHelper::diff("alpha\nbravo\ncharlie", "alpha\nzulu\ncharlie");

        $changed = array_values(array_filter($lines, fn (array $l) => $l['type'] === 'change'));
        self::assertCount(1, $changed);
        self::assertSame('bravo', $changed[0]['old']);
        self::assertSame('zulu', $changed[0]['new']);
    }

    public function testHtmlToLinesExtractsBlocksWithoutTags(): void
    {
        $html  = '<p>First paragraph</p><h2>A heading</h2><ul><li>Item one</li><li>Item two</li></ul>';
        $lines = DiffHelper::htmlToLines($html);

        self::assertSame(['First paragraph', 'A heading', 'Item one', 'Item two'], $lines);
    }

    public function testHtmlToLinesPlainTextInputFallback(): void
    {
        self::assertSame(['line one', 'line two'], DiffHelper::htmlToLines("line one\nline two"));
        self::assertSame([], DiffHelper::htmlToLines(null));
        self::assertSame([], DiffHelper::htmlToLines('   '));
    }

    public function testHtmlToLinesDecodesEntitiesAndCollapsesWhitespace(): void
    {
        $html  = "<p>Caf&eacute; test</p><p>&nbsp;&nbsp;&nbsp;Spaced&nbsp;out</p>";
        $lines = DiffHelper::htmlToLines($html);

        self::assertSame(['Café test', 'Spaced out'], $lines);
    }

    public function testDiffHtmlComparesReadableTextInsteadOfMarkup(): void
    {
        $diff = DiffHelper::toHtmlFromHtml('<p>Hello world</p>', '<p>Hello brave new world</p>');

        self::assertStringContainsString('vl-diff-add', $diff);
        self::assertStringContainsString('<span class="vl-w-add">brave new</span>', $diff);
        self::assertStringNotContainsString('&lt;p&gt;', $diff);
    }

    public function testRenderLinesEscapesHtmlContent(): void
    {
        $diff = DiffHelper::toHtml('<p>Safe</p>', '<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $diff);
        self::assertStringContainsString('&lt;p&gt;', $diff);
    }

    public function testWordLevelHighlightMarksChangedRegionOnly(): void
    {
        $lines = DiffHelper::diff('Hello brave world', 'Hello wide world');
        $html  = DiffHelper::renderLines($lines);

        self::assertStringContainsString('<span class="vl-w-del">brave</span>', $html);
        self::assertStringContainsString('<span class="vl-w-add">wide</span>', $html);
        self::assertStringContainsString('Hello', $html);
        self::assertStringNotContainsString('<span class="vl-w-del">Hello', $html);
    }

    public function testPureAdditionHighlightsInsertedWords(): void
    {
        $html = DiffHelper::renderLines(
            DiffHelper::diff('Keep this', 'Keep this and more'),
        );

        self::assertStringContainsString('<span class="vl-w-add">and more</span>', $html);
    }

    public function testMultipleChangedBlocksPairInOrder(): void
    {
        $old  = "<h2>Başlık</h2><p>Merhaba dünya bugün hava çok güzel</p><p>Elma</p>";
        $new  = "<h2>Yeni Başlık</h2><p>Merhaba dünya bugün hava fena değil</p><p>Elma</p><li>Armut</li>";
        $html = DiffHelper::toHtmlFromHtml($old, $new);

        self::assertStringContainsString(
            '<span class="vl-w-del">çok güzel</span>',
            $html,
        );
        self::assertStringContainsString(
            '<span class="vl-w-add">fena değil</span>',
            $html,
        );
        self::assertStringContainsString('<span class="vl-w-add">Yeni</span>', $html);
        self::assertSame(1, substr_count($html, 'vl-diff-same'));
    }

    public function testToReadableTextProducesOneBlockPerLine(): void
    {
        $html = '<h1>hello 1</h1><p>Intro text</p><!--nextpage--><h2>hello 2</h2><ul><li>Item</li></ul>';
        $text = DiffHelper::toReadableText($html);

        self::assertSame("hello 1\nIntro text\n[nextpage]\nhello 2\nItem", $text);
    }

    public function testToReadableTextPreservesShortcodesAndMarkers(): void
    {
        $html = '[toc]<p></p><p>First page</p><!--More--><p>Second page</p>';
        $text = DiffHelper::toReadableText($html);

        self::assertStringContainsString('[toc]', $text);
        self::assertStringContainsString('[more]', $text);
        self::assertSame("[toc]\nFirst page\n[more]\nSecond page", $text);
    }

    public function testToReadableTextHandlesEmptyAndPlainInput(): void
    {
        self::assertSame('', DiffHelper::toReadableText(null));
        self::assertSame('', DiffHelper::toReadableText('   '));
        self::assertSame("line one\nline two", DiffHelper::toReadableText("line one\nline two"));
    }

    public function testEmptyAndNullInputsProduceEmptyDiff(): void
    {
        self::assertSame([], DiffHelper::diff(null, null));
        self::assertSame([], DiffHelper::diff('', ''));
        self::assertSame([], DiffHelper::htmlToLines(null));

        $lines = DiffHelper::diff(null, 'content');
        self::assertCount(1, $lines);
        self::assertSame('add', $lines[0]['type']);
        self::assertSame('content', $lines[0]['new']);
    }
}
