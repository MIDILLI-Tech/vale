<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Content;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Content\ContentMarkerNormalizer;

final class ContentMarkerNormalizerTest extends TestCase
{
    public function testCanonicalNextPageIsUnchanged(): void
    {
        $body = '<p>Intro</p><!--nextpage--><p>Rest</p>';

        $this->assertSame($body, ContentMarkerNormalizer::normalize($body));
    }

    public function testWhitespaceAndCaseVariantsAreNormalized(): void
    {
        $this->assertSame(
            '<!--nextpage-->',
            ContentMarkerNormalizer::normalize('<!-- nextpage -->')
        );
        $this->assertSame(
            '<!--nextpage-->',
            ContentMarkerNormalizer::normalize('<!--NEXTpage-->')
        );
    }

    public function testNextPageTitleAttributeIsPreserved(): void
    {
        $this->assertSame(
            '<!--nextpage title="Advanced Details"-->',
            ContentMarkerNormalizer::normalize('<!-- nextpage title = "Advanced Details" -->')
        );
        $this->assertSame(
            '<!--nextpage title="Advanced Details"-->',
            ContentMarkerNormalizer::normalize('<!--nextpage title="Advanced Details"-->')
        );
    }

    public function testNextPageTitleWithApostropheIsPreserved(): void
    {
        // Apostrophe survives parsing and is safely re-escaped in the attribute
        $this->assertSame(
            '<!--nextpage title="Don&apos;t Stop"-->',
            ContentMarkerNormalizer::normalize('<!--nextpage title="Don\'t Stop"-->')
        );
        $this->assertSame(
            '<!--nextpage title="It&apos;s Ready"-->',
            ContentMarkerNormalizer::normalize("<!--nextpage title='It's Ready'-->")
        );
    }

    public function testMoreTextWithApostropheIsPreserved(): void
    {
        $this->assertSame(
            '<!--more text="Don&apos;t Miss Out"-->',
            ContentMarkerNormalizer::normalize('<!--more text="Don\'t Miss Out"-->')
        );
    }

    public function testWordPressPlainTextMoreIsConvertedToAttribute(): void
    {
        $this->assertSame(
            '<!--more text="Keep reading"-->',
            ContentMarkerNormalizer::normalize('<!--more Keep reading -->')
        );
    }

    public function testMoreVariantsAreNormalized(): void
    {
        $this->assertSame('<!--more-->', ContentMarkerNormalizer::normalize('<!-- more -->'));
        $this->assertSame('<!--more-->', ContentMarkerNormalizer::normalize('<!--MORE-->'));
        $this->assertSame(
            '<!--more text="Devamını Oku"-->',
            ContentMarkerNormalizer::normalize("<!--more text='Devamını Oku'-->")
        );
    }

    public function testCanonicalMoreWithTextIsUnchanged(): void
    {
        $marker = '<!--more text="Keep reading"-->';

        $this->assertSame($marker, ContentMarkerNormalizer::normalize($marker));
    }

    public function testParagraphWrappedMarkersAreUnwrapped(): void
    {
        $this->assertSame(
            '<p>One</p><!--nextpage--><p>Two</p>',
            ContentMarkerNormalizer::normalize('<p>One</p><p><!--nextpage--></p><p>Two</p>')
        );
        $this->assertSame(
            '<p>One</p><!--more--><p>Two</p>',
            ContentMarkerNormalizer::normalize('<p>One</p><p><!--more--></p><p>Two</p>')
        );
    }

    public function testFullImportBodyNormalization(): void
    {
        $imported = '<p>Intro</p><p><!-- nextpage --></p>'
            . "<!--more Devamını oku -->\n<p>Teaser continues</p>";

        $expected = '<p>Intro</p><!--nextpage-->' . "<!--more text=\"Devamını oku\"-->\n<p>Teaser continues</p>";

        $this->assertSame($expected, ContentMarkerNormalizer::normalize($imported));
    }

    public function testSplitOnMoreWithoutMarkerReturnsFullBody(): void
    {
        $split = ContentMarkerNormalizer::splitOnMore('<p>No marker here</p>');

        $this->assertSame('<p>No marker here</p>', $split['teaser']);
        $this->assertNull($split['label']);
        $this->assertFalse($split['has_more']);
    }

    public function testSplitOnMoreExtractsTeaserAndLabel(): void
    {
        $body   = '<p>Teaser</p><!--more text="Continue &amp; enjoy"--><p>Hidden</p>';
        $split  = ContentMarkerNormalizer::splitOnMore($body);

        $this->assertSame('<p>Teaser</p>', $split['teaser']);
        $this->assertSame('Continue & enjoy', $split['label']);
        $this->assertTrue($split['has_more']);
    }

    public function testSplitOnMorePlainMarkerHasNullLabel(): void
    {
        $split = ContentMarkerNormalizer::splitOnMore('<p>A</p><!--more--><p>B</p>');

        $this->assertSame('<p>A</p>', $split['teaser']);
        $this->assertNull($split['label']);
        $this->assertTrue($split['has_more']);
    }

    public function testSplitOnMoreHandlesNullAndEmpty(): void
    {
        $this->assertFalse(ContentMarkerNormalizer::splitOnMore(null)['has_more']);
        $this->assertNull(ContentMarkerNormalizer::splitOnMore(null)['label']);
        $this->assertSame('', ContentMarkerNormalizer::splitOnMore('')['teaser']);
    }
}
