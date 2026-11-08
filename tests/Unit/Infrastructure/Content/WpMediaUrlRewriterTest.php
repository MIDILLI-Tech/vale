<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Content;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Content\WpMediaUrlRewriter;

final class WpMediaUrlRewriterTest extends TestCase
{
    public function testExactAttachmentUrlMapsToStoredPath(): void
    {
        $plan = WpMediaUrlRewriter::plan([
            ['url' => 'https://old.example.com/wp-content/uploads/2020/05/pic.jpg', 'path' => '2020/05/pic.jpg'],
        ]);

        $this->assertSame(['https://old.example.com/wp-content/uploads/2020/05/pic.jpg' => '/uploads/2020/05/pic.jpg'], $plan['exact']);
        $this->assertSame(['https://old.example.com/wp-content/uploads/' => '/uploads/'], $plan['bases']);
    }

    public function testExactRewriteWinsOverBaseForSameFile(): void
    {
        $plan = WpMediaUrlRewriter::plan([
            ['url' => 'https://old.example.com/wp-content/uploads/2020/05/pic.jpg', 'path' => '2020/05/pic.jpg'],
        ]);

        $html = '<img src="https://old.example.com/wp-content/uploads/2020/05/pic.jpg">';
        $out  = WpMediaUrlRewriter::apply($html, $plan['exact'], $plan['bases']);

        $this->assertSame('<img src="/uploads/2020/05/pic.jpg">', $out);
    }

    public function testSubdirectoryWordPressInstall(): void
    {
        $plan = WpMediaUrlRewriter::plan([
            ['url' => 'https://old.example.com/blog/wp-content/uploads/2019/11/hero.jpg', 'path' => '2019/11/hero.jpg'],
        ]);

        $html = '<img src="https://old.example.com/blog/wp-content/uploads/2019/11/hero.jpg">';
        $out  = WpMediaUrlRewriter::apply($html, $plan['exact'], $plan['bases']);

        $this->assertSame('<img src="/uploads/2019/11/hero.jpg">', $out);
    }

    public function testSubdirectoryValeInstallPrefixesUploads(): void
    {
        $plan = WpMediaUrlRewriter::plan([
            ['url' => 'https://old.example.com/blog/wp-content/uploads/2019/11/hero.jpg', 'path' => '2019/11/hero.jpg'],
        ], '/vale');

        $html = '<img src="https://old.example.com/blog/wp-content/uploads/2019/11/hero.jpg">';
        $out  = WpMediaUrlRewriter::apply($html, $plan['exact'], $plan['bases']);

        $this->assertSame('<img src="/vale/uploads/2019/11/hero.jpg">', $out);
        $this->assertSame(
            ['https://old.example.com/blog/wp-content/uploads/' => '/vale/uploads/'],
            $plan['bases']
        );

        $written = WpMediaUrlRewriter::apply(
            '<img srcset="https://old.example.com/blog/wp-content/uploads/2019/11/hero-768x432.jpg 768w">',
            $plan['exact'],
            $plan['bases']
        );
        $this->assertSame('<img srcset="/vale/uploads/2019/11/hero-768x432.jpg 768w">', $written);
    }

    public function testVariantUrlIsRewrittenViaBasePrefix(): void
    {
        $plan = WpMediaUrlRewriter::plan([
            ['url' => 'https://old.example.com/wp-content/uploads/2021/03/photo.jpg', 'path' => '2021/03/photo.jpg'],
        ]);

        $html = '<img src="https://old.example.com/wp-content/uploads/2021/03/photo-300x200.jpg">'
            . '<img srcset="https://old.example.com/wp-content/uploads/2021/03/photo-768x432.jpg 768w">';
        $out  = WpMediaUrlRewriter::apply($html, $plan['exact'], $plan['bases']);

        $this->assertSame(
            '<img src="/uploads/2021/03/photo-300x200.jpg">'
            . '<img srcset="/uploads/2021/03/photo-768x432.jpg 768w">',
            $out
        );
    }

    public function testRewriterDoesNotTouchUnrelatedUrls(): void
    {
        $plan = WpMediaUrlRewriter::plan([
            ['url' => 'https://old.example.com/wp-content/uploads/2020/05/pic.jpg', 'path' => '2020/05/pic.jpg'],
        ]);

        $html = '<a href="https://old.example.com/about">About</a>'
            . '<img src="https://other.example.net/wp-content/uploads/x.png">';
        $out  = WpMediaUrlRewriter::apply($html, $plan['exact'], $plan['bases']);

        $this->assertSame($html, $out);
    }

    public function testDataUriAndEmptyEntriesAreIgnored(): void
    {
        $plan = WpMediaUrlRewriter::plan([
            ['url' => 'data:image/png;base64,abc', 'path' => 'x.png'],
            ['url' => '', 'path' => 'y.png'],
            ['url' => 'https://old.example.com/files/a.pdf', 'path' => 'files/a.pdf'],
        ]);

        // Only the plain http(s) URL without an uploads base produces an exact map.
        $this->assertSame(['https://old.example.com/files/a.pdf' => '/uploads/files/a.pdf'], $plan['exact']);
        $this->assertSame([], $plan['bases']);
    }

    public function testPlanIsIdempotentAcrossModes(): void
    {
        $planA = WpMediaUrlRewriter::plan([
            ['url' => 'https://old.example.com/wp-content/uploads/2020/05/pic.jpg', 'path' => '2020/05/pic.jpg'],
        ]);
        $planB = WpMediaUrlRewriter::plan([
            ['url' => 'https://old.example.com/wp-content/uploads/2020/05/pic.jpg', 'path' => '2020/05/pic.jpg'],
        ]);

        $this->assertSame($planA, $planB);
    }

    public function testOriginalForVariantStripsSizeSuffix(): void
    {
        self::assertSame(
            '/uploads/2025/08/midiwebtool2.png',
            WpMediaUrlRewriter::originalForVariant('/uploads/2025/08/midiwebtool2-1024x490.png')
        );
        self::assertSame(
            'https://old.example.com/wp-content/uploads/2021/03/photo.jpg',
            WpMediaUrlRewriter::originalForVariant('https://old.example.com/wp-content/uploads/2021/03/photo-300x200.jpg')
        );
        self::assertSame(
            '/uploads/a/photo.png',
            WpMediaUrlRewriter::originalForVariant('/uploads/a/photo-scaled.png')
        );
    }

    public function testOriginalForVariantPreservesQueryAndFragment(): void
    {
        self::assertSame(
            '/uploads/a/photo.png?ver=1#frag',
            WpMediaUrlRewriter::originalForVariant('/uploads/a/photo-300x200.png?ver=1#frag')
        );
    }

    public function testOriginalForVariantReturnsNullWithoutSuffix(): void
    {
        self::assertNull(WpMediaUrlRewriter::originalForVariant('/uploads/2025/08/midiwebtool2.png'));
        self::assertNull(WpMediaUrlRewriter::originalForVariant('/uploads/2025/08/photo.png?300x200'));
        self::assertNull(WpMediaUrlRewriter::originalForVariant('https://example.com/about'));
    }
}
