<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Content;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Content\ContentSummary;

final class ContentSummaryTest extends TestCase
{
    public function testExcerptWinsOverBody(): void
    {
        $teaser = ContentSummary::blockTeaser('<p>Body text</p>', 'Manual summary', 55);

        $this->assertSame(ContentSummary::KIND_EXCERPT, $teaser['kind']);
        $this->assertSame('Manual summary', $teaser['html']);
        $this->assertNull($teaser['label']);
    }

    public function testMoreMarkerWinsOverAutoSummary(): void
    {
        $body   = '<p>Teaser <strong>before</strong> the break</p><!--more text="Keep going"--><p>Hidden rest</p>';
        $teaser = ContentSummary::blockTeaser($body, null, 55);

        $this->assertSame(ContentSummary::KIND_MORE, $teaser['kind']);
        $this->assertSame('<p>Teaser <strong>before</strong> the break</p>', $teaser['html']);
        $this->assertSame('Keep going', $teaser['label']);
    }

    public function testAutoSummaryKeepsFormattingOfCompleteBlocks(): void
    {
        $body   = '<p>One <strong>two</strong> three</p><p>four</p>';
        $teaser = ContentSummary::blockTeaser($body, null, 4);

        $this->assertSame(ContentSummary::KIND_AUTO, $teaser['kind']);
        $this->assertSame('<p>One <strong>two</strong> three</p><p>four</p>', $teaser['html']);
    }

    public function testAutoSummaryCutsTheOverflowingBlock(): void
    {
        $teaser = ContentSummary::blockTeaser('<p>One two three four five.</p>', null, 3);

        $this->assertSame(ContentSummary::KIND_AUTO, $teaser['kind']);
        $this->assertSame('<p>One two three&hellip;</p>', $teaser['html']);
    }

    public function testAutoSummaryDropsMediaEmbedsAndTables(): void
    {
        $body = '<figure><img src="/a.jpg"><figcaption>Caption</figcaption></figure>'
            . '<p>Hello world</p>'
            . '<iframe src="https://example.test/embed"></iframe>'
            . '<table><tr><td>Cell</td></tr></table>';

        $this->assertSame('<p>Hello world</p>', ContentSummary::blockTeaser($body, null, 50)['html']);
    }

    public function testAutoSummaryDropsMarkersCommentsAndShortcodes(): void
    {
        $body = '<!-- wp:paragraph --><p>Kept text</p><!-- /wp:paragraph -->'
            . '<p>[toc]</p><p>[newsletter_form title="News"]</p>';

        $this->assertSame('<p>Kept text</p>', ContentSummary::blockTeaser($body, null, 50)['html']);
    }

    public function testAutoSummaryConvertsHeadingsToBoldParagraphs(): void
    {
        $teaser = ContentSummary::blockTeaser('<h2>Release notes</h2><p>Body copy</p>', null, 10);

        $this->assertSame('<p><strong>Release notes</strong></p><p>Body copy</p>', $teaser['html']);
    }

    public function testAutoSummaryUnwrapsContainerElements(): void
    {
        $body = '<div class="wp-block-group"><div><p>Nested copy</p></div></div>';

        $this->assertSame('<p>Nested copy</p>', ContentSummary::blockTeaser($body, null, 50)['html']);
    }

    public function testAutoSummaryIsDisabledWithZeroWords(): void
    {
        $teaser = ContentSummary::blockTeaser('<p>Body text</p>', null, 0);

        $this->assertSame(ContentSummary::KIND_EMPTY, $teaser['kind']);
        $this->assertSame('', $teaser['html']);
    }

    public function testEmptyBodyProducesEmptyTeaser(): void
    {
        $this->assertSame(ContentSummary::KIND_EMPTY, ContentSummary::blockTeaser('   ', null, 55)['kind']);
        $this->assertSame(ContentSummary::KIND_EMPTY, ContentSummary::blockTeaser(null, null, 55)['kind']);
    }

    public function testPlainTeaserPrefersExcerpt(): void
    {
        $this->assertSame(
            'Manual summary',
            ContentSummary::plainTeaser('<p>Body text</p>', 'Manual summary', 160)
        );
    }

    public function testPlainTeaserStopsAtMoreMarker(): void
    {
        $body = '<p>Teaser line here</p><!--more--><p>Hidden rest</p>';

        $this->assertSame('Teaser line here', ContentSummary::plainTeaser($body, null, 160));
    }

    public function testPlainTeaserStripsMarkupAndDecodesEntities(): void
    {
        $body = '<p>Hello <strong>bold</strong>&nbsp;world &amp; friends</p>';

        $this->assertSame('Hello bold world & friends', ContentSummary::plainTeaser($body, null, 160));
    }

    public function testPlainTeaserCutsAtAWordBoundaryWithEllipsis(): void
    {
        $this->assertSame(
            'Merhaba dünya, çok…',
            ContentSummary::plainTeaser('<p>Merhaba dünya, çok güzel bir gün.</p>', null, 20)
        );
    }

    public function testPlainTeaserKeepsMultibyteCharactersIntact(): void
    {
        $result = ContentSummary::plainTeaser('<p>Çok güzel bir gün</p>', null, 9);

        $this->assertSame('Çok güzel…', $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }
}
