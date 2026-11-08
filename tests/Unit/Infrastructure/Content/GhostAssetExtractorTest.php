<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Content;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Content\GhostAssetExtractor;

final class GhostAssetExtractorTest extends TestCase
{
    public function testExtractsFeatureOgAndInlineImages(): void
    {
        $post = [
            'feature_image' => 'https://ghost.test/content/images/2024/05/cover.jpg',
            'og_image'      => 'https://ghost.test/content/images/2024/05/cover.jpg',
            'html'          => '<p>A</p><img src="/content/images/a.png">'
                . "<img src='https://cdn.other.io/b.webp'>"
                . '<img src="/content/images/a.png">',
        ];

        $urls = GhostAssetExtractor::extractImageUrls($post);

        $this->assertSame(
            [
                'https://ghost.test/content/images/2024/05/cover.jpg',
                '/content/images/a.png',
                'https://cdn.other.io/b.webp',
            ],
            $urls
        );
    }

    public function testSkipsDataUrisAndEmpty(): void
    {
        $post = [
            'feature_image' => '',
            'html'          => '<img src="data:image/png;base64,AAAA"><img src="">',
        ];

        $this->assertSame([], GhostAssetExtractor::extractImageUrls($post));
    }

    public function testNormalizePassthroughAbsoluteAndProtocolRelative(): void
    {
        $this->assertSame(
            'https://x.test/i.jpg',
            GhostAssetExtractor::normalizeImageUrl('https://x.test/i.jpg', 'https://base.test')
        );
        $this->assertSame(
            'http://x.test/i.jpg',
            GhostAssetExtractor::normalizeImageUrl('//x.test/i.jpg', 'http://base.test')
        );
        $this->assertSame(
            'https://x.test/i.jpg',
            GhostAssetExtractor::normalizeImageUrl('//x.test/i.jpg', null)
        );
    }

    public function testNormalizeResolvesRootRelativeAgainstBase(): void
    {
        $this->assertSame(
            'https://ghost.test/content/images/a.png',
            GhostAssetExtractor::normalizeImageUrl('/content/images/a.png', 'https://ghost.test/sub/')
        );
        $this->assertNull(GhostAssetExtractor::normalizeImageUrl('/content/images/a.png', null));
    }

    public function testNormalizeResolvesGhostUrlPlaceholder(): void
    {
        $this->assertSame(
            'https://ghost.test/content/images/2024/05/photo.gif',
            GhostAssetExtractor::normalizeImageUrl('__GHOST_URL__/content/images/2024/05/photo.gif', 'https://ghost.test/')
        );
        $this->assertNull(GhostAssetExtractor::normalizeImageUrl('__GHOST_URL__/content/images/a.png', null));
    }

    public function testNormalizeRejectsDataAndRelativeFilenames(): void
    {
        $this->assertNull(GhostAssetExtractor::normalizeImageUrl('data:image/png;base64,AA', 'https://b.test'));
        $this->assertNull(GhostAssetExtractor::normalizeImageUrl('plain.jpg', 'https://b.test'));
    }

    public function testExtractsSrcsetCandidates(): void
    {
        $post = [
            'html' => '<figure class="kg-card kg-image-card"><img src="__GHOST_URL__/content/images/2024/05/photo.gif"'
                . ' srcset="__GHOST_URL__/content/images/size/w600/2024/05/photo.gif 600w,'
                . ' __GHOST_URL__/content/images/size/w1000/2024/05/photo.gif 1000w,'
                . ' __GHOST_URL__/content/images/2024/05/photo.gif 1280w"'
                . ' sizes="(min-width: 720px) 720px" loading="lazy"></figure>',
        ];

        $this->assertSame(
            [
                '__GHOST_URL__/content/images/2024/05/photo.gif',
                '__GHOST_URL__/content/images/size/w600/2024/05/photo.gif',
                '__GHOST_URL__/content/images/size/w1000/2024/05/photo.gif',
            ],
            GhostAssetExtractor::extractImageUrls($post)
        );
    }

    public function testNormalizeResolvesGhostUrlSizePlaceholder(): void
    {
        $this->assertSame(
            'https://ghost.test/content/images/size/w600/2024/05/photo.gif',
            GhostAssetExtractor::normalizeImageUrl(
                '__GHOST_URL__/content/images/size/w600/2024/05/photo.gif',
                'https://ghost.test/'
            )
        );
    }

    public function testRewriteHtmlReplacesSrcsetCandidates(): void
    {
        $html = '<img srcset="https://g.test/content/images/size/w600/a.jpg 600w,'
            . ' https://g.test/content/images/size/w1000/a.jpg 1000w,'
            . ' /uploads/a.jpg 1280w">';
        $out  = GhostAssetExtractor::rewriteHtml($html, [
            'https://g.test/content/images/size/w600/a.jpg'  => '/uploads/size/w600/a.jpg',
            'https://g.test/content/images/size/w1000/a.jpg' => '/uploads/size/w1000/a.jpg',
        ]);

        $this->assertSame(
            '<img srcset="/uploads/size/w600/a.jpg 600w, /uploads/size/w1000/a.jpg 1000w, /uploads/a.jpg 1280w">',
            $out
        );
    }

    public function testRewriteHtmlReplacesAllOccurrences(): void
    {
        $html  = '<img src="https://g.test/content/images/a.jpg"><img src="/content/images/a.jpg">';
        $out   = GhostAssetExtractor::rewriteHtml($html, [
            'https://g.test/content/images/a.jpg' => '/uploads/a.jpg',
            '/content/images/a.jpg'               => '/uploads/a.jpg',
        ]);

        $this->assertSame('<img src="/uploads/a.jpg"><img src="/uploads/a.jpg">', $out);
    }
}
