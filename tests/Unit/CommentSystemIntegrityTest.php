<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommentSystemIntegrityTest extends TestCase
{
    /** @return array<string, array<string, string>> */
    private static function mediaEmbeds(): array
    {
        $referenceData = require __DIR__ . '/../../config/reference-data.php';

        self::assertIsArray($referenceData);
        self::assertArrayHasKey('media_embeds', $referenceData);
        self::assertIsArray($referenceData['media_embeds']);

        return $referenceData['media_embeds'];
    }

    public function testReferenceDataDeclaresFrameSourcesForExternalCommentSystems(): void
    {
        $embeds = self::mediaEmbeds();

        self::assertArrayHasKey('disqus', $embeds);
        self::assertArrayHasKey('giscus', $embeds);
        self::assertArrayHasKey('facebook_comments', $embeds);

        self::assertStringContainsString('disqus.com', $embeds['disqus']['frame_src']);
        self::assertStringContainsString('giscus.app', $embeds['giscus']['frame_src']);
        self::assertStringContainsString('facebook.com', $embeds['facebook_comments']['frame_src']);
    }

    public function testFacebookSdkUrlTemplateComesFromTheCatalog(): void
    {
        $sdkUrl = self::mediaEmbeds()['facebook_comments']['sdk_url'];

        self::assertStringContainsString('connect.facebook.net/{locale}/sdk.js', $sdkUrl);
        self::assertStringContainsString('appId={app_id}', $sdkUrl);
        self::assertMatchesRegularExpression('/[#&]version=v\d+\.\d+/', $sdkUrl);
    }

    #[DataProvider('commentsPartialProvider')]
    public function testCommentsPartialHandlesEveryCommentSystem(string $path): void
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        $expected = [
            "comment_system == 'disqus'",
            "comment_system == 'giscus'",
            "comment_system == 'facebook'",
            'https://giscus.app/client.js',
            'fb-root',
        ];

        foreach ($expected as $needle) {
            self::assertStringContainsString($needle, $content, $path . ' is missing: ' . $needle);
        }
    }

    #[DataProvider('commentsPartialProvider')]
    public function testCommentsPartialReadsTheSdkUrlFromTheCatalogOnly(string $path): void
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        self::assertStringContainsString('media_embeds.facebook_comments.sdk_url', $content);
        self::assertStringNotContainsString(
            'connect.facebook.net',
            $content,
            $path . ' must not hardcode the Facebook SDK URL (config/reference-data.php owns it).',
        );
    }

    /** @return array<string, array{0: string}> */
    public static function commentsPartialProvider(): array
    {
        return [
            'core' => [__DIR__ . '/../../resources/views/partials/comments.twig'],
            'nova' => [__DIR__ . '/../../themes/nova/templates/partials/comments.twig'],
        ];
    }

    public function testNovaPostTemplateRendersTheCommentsPartial(): void
    {
        $content = file_get_contents(__DIR__ . '/../../themes/nova/templates/post.twig');
        self::assertNotFalse($content);

        self::assertStringContainsString("include 'partials/comments.twig'", $content);
    }
}
