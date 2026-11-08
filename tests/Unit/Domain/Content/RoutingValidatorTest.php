<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Content;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\RoutingPrefixTypes;
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class RoutingValidatorTest extends TestCase
{
    /**
     * @param array<string, string> $settings
     * @param ContentType[] $types
     * @param Post[] $pages
     * @param array<string, string> $pagePaths slug => full nested path
     */
    private function validator(
        array $settings,
        array $types = [],
        array $pages = [],
        array $pagePaths = [],
    ): RoutingValidator {
        $settingsRepo = $this->createStub(SettingsRepositoryInterface::class);
        $settingsRepo->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $settings[$key] ?? $default,
        );

        $typeRepo = $this->createStub(ContentTypeRepositoryInterface::class);
        $typeRepo->method('findAll')->willReturn($types);
        $typeRepo->method('findActiveCustom')->willReturn($types);

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findAll')->willReturn($pages);
        $postRepo->method('buildPagePath')->willReturnCallback(
            static fn (Post $post): string => $pagePaths[(string) $post->getSlug()] ?? (string) $post->getSlug(),
        );

        return new RoutingValidator(
            $settingsRepo,
            $typeRepo,
            $postRepo,
            ['tr', 'de'],
        );
    }

    private function publishedPage(string $slug, string $title = 'Page'): Post
    {
        $page = new Post($title, new Slug($slug), 1);
        $page->publish();

        return $page;
    }

    public function testAllTypesCombineContentAndAuthTypes(): void
    {
        self::assertSame(
            [...RoutingPrefixTypes::CONTENT_TYPES, ...RoutingPrefixTypes::AUTH_TYPES],
            RoutingPrefixTypes::ALL_TYPES,
        );
        self::assertSame('routing_blog_prefix', RoutingPrefixTypes::settingKey('blog'));
        self::assertSame('routing_login_prefix', RoutingPrefixTypes::settingKey('login'));
    }

    public function testReservedSystemPrefixesConflict(): void
    {
        $validator = $this->validator([]);

        self::assertStringContainsString('system route', (string) $validator->getConflictError('/robots.txt'));
        self::assertStringContainsString('system route', (string) $validator->getConflictError('/ai-robots.txt'));
        self::assertStringContainsString('system route', (string) $validator->getConflictError('/sitemap.xml'));
        self::assertStringContainsString('system route', (string) $validator->getConflictError('/admin'));
    }

    public function testGlobalPrefixConflictDetected(): void
    {
        $validator = $this->validator(['routing_blog_prefix' => '/haber']);

        self::assertStringContainsString('another routing setting', (string) $validator->getConflictError('/haber'));
        self::assertNull($validator->getConflictError('/farkli', 'routing_blog_prefix'));
    }

    public function testHyphenatedAuthRoutesConflictWithPages(): void
    {
        // No stored settings: validator must fall back to the hyphenated
        // route defaults from routes/web.php, not '/forgot_password' etc.
        $validator = $this->validator([]);

        foreach (['/forgot-password', '/reset-password', '/verify-email'] as $path) {
            self::assertStringContainsString(
                'another routing setting',
                (string) $validator->getPagePathConflictError($path),
                $path
            );
        }
        self::assertNull($validator->getPagePathConflictError('/my-page'));
    }

    public function testHyphenatedAuthRouteBlocksPrefixSwap(): void
    {
        $validator = $this->validator([]);

        self::assertStringContainsString(
            'another routing setting',
            (string) $validator->getConflictError('/forgot-password', 'routing_shop_prefix')
        );
    }

    public function testLocalizedPrefixConflictDetected(): void
    {
        $validator = $this->validator(['routing_blog_prefix_tr' => '/yazi']);

        self::assertStringContainsString('routing_blog_prefix_tr', (string) $validator->getConflictError('/yazi'));
    }

    public function testPagePrefixMayLiveAtRoot(): void
    {
        $validator = $this->validator([]);

        self::assertNull($validator->getConflictError('/', 'routing_page_prefix'));
        self::assertStringContainsString('cannot be empty', (string) $validator->getConflictError('/'));
    }

    public function testCptBasePathConflictsWithExistingPageSlug(): void
    {
        $validator = $this->validator(
            ['routing_page_prefix' => ''],
            [],
            [$this->publishedPage('test')],
        );

        self::assertStringContainsString('existing page', (string) $validator->getConflictError('/test'));
    }

    public function testCptBasePathWithDifferentPrefixDoesNotConflict(): void
    {
        $validator = $this->validator(
            ['routing_page_prefix' => ''],
            [],
            [$this->publishedPage('test')],
        );

        self::assertNull($validator->getConflictError('/d/test'));
    }

    public function testCptBasePathConflictsWithBlogAndShopPrefixes(): void
    {
        $validator = $this->validator([
            'routing_blog_prefix' => '/blog',
            'routing_shop_prefix' => '/shop',
            'routing_page_prefix' => '',
        ]);

        self::assertStringContainsString('another routing setting', (string) $validator->getConflictError('/blog'));
        self::assertStringContainsString('another routing setting', (string) $validator->getConflictError('/shop'));
        self::assertNull($validator->getConflictError('/d/blog'));
    }

    public function testPagePrefixDisambiguatesCptAndPage(): void
    {
        $validator = $this->validator(
            ['routing_page_prefix' => '/pages'],
            [],
            [$this->publishedPage('test')],
        );

        // Page lives at /pages/test, so a CPT at /test is free.
        self::assertNull($validator->getConflictError('/test'));
        // A CPT at /pages/test is still blocked (via the page routing setting).
        self::assertStringContainsString('conflicts', (string) $validator->getConflictError('/pages/test'));
    }

    public function testNestedPagePathConflict(): void
    {
        $validator = $this->validator(
            ['routing_page_prefix' => ''],
            [],
            [$this->publishedPage('docs'), $this->publishedPage('test')],
            ['docs' => 'docs', 'test' => 'docs/test'],
        );

        self::assertStringContainsString('existing page', (string) $validator->getConflictError('/docs/test'));
        self::assertNull($validator->getConflictError('/other/test'));
    }

    public function testTrashPagesAreIgnored(): void
    {
        $trashed = new Post('Old', new Slug('test'), 1);
        $trashed->trash();
        $validator = $this->validator(['routing_page_prefix' => ''], [], [$trashed]);

        self::assertNull($validator->getConflictError('/test'));
    }

    public function testPagePathConflictAgainstCptAndGlobals(): void
    {
        $type      = new ContentType('Test', new Slug('test'));
        $validator = $this->validator(
            ['routing_blog_prefix' => '/blog', 'routing_page_prefix' => ''],
            [$type],
        );

        self::assertStringContainsString('content type', (string) $validator->getPagePathConflictError('/test'));
        self::assertStringContainsString('another routing setting', (string) $validator->getPagePathConflictError('/blog'));
        self::assertNull($validator->getPagePathConflictError('/about'));
    }

    public function testPagePathUnderCustomPagePrefixIsFree(): void
    {
        $type      = new ContentType('Test', new Slug('test'));
        $validator = $this->validator(['routing_page_prefix' => '/pages'], [$type]);

        self::assertNull($validator->getPagePathConflictError('/pages/test'));
    }

    public function testFindAllConflictsReportsBothSides(): void
    {
        $type      = new ContentType('Test', new Slug('test'));
        $validator = $this->validator(
            ['routing_page_prefix' => ''],
            [$type],
            [$this->publishedPage('test')],
        );

        $conflicts = $validator->findAllConflicts();

        self::assertCount(2, $conflicts);
        self::assertSame(['content-type', 'page'], array_column($conflicts, 'scope'));
    }

    public function testFindAllConflictsEmptyWhenClean(): void
    {
        $validator = $this->validator(['routing_page_prefix' => ''], [], [$this->publishedPage('about')]);

        self::assertSame([], $validator->findAllConflicts());
    }

    public function testRootPrefixRejectedForNonPageKeys(): void
    {
        $validator = $this->validator([]);

        self::assertStringContainsString('cannot be empty', (string) $validator->getConflictError('/', 'routing_category_prefix'));
        self::assertStringContainsString('cannot be empty', (string) $validator->getConflictError('', 'routing_blog_prefix'));
        self::assertNull($validator->getConflictError('/', 'routing_page_prefix'));
    }

    public function testSimultaneousSwapValidatesAtomicallyWithOverlay(): void
    {
        $validator = $this->validator([
            'routing_blog_prefix' => '/blog',
            'routing_shop_prefix' => '/shop',
        ]);
        $overlay = [
            'routing_blog_prefix' => '/shop',
            'routing_shop_prefix' => '/blog',
        ];

        // Without the overlay each new value collides with the other's stored value.
        self::assertNotNull($validator->getConflictError('/shop', 'routing_blog_prefix'));
        // With the overlay the swap is clean.
        self::assertNull($validator->getConflictError('/shop', 'routing_blog_prefix', null, $overlay));
        self::assertNull($validator->getConflictError('/blog', 'routing_shop_prefix', null, $overlay));
    }

    public function testFindAllConflictsFlagsEmptyAndDuplicatePrefixes(): void
    {
        $validator = $this->validator([
            'routing_blog_prefix'     => '/x',
            'routing_shop_prefix'     => '/x',
            'routing_category_prefix' => '/',
            'routing_page_prefix'     => '',
        ]);

        $conflicts = $validator->findAllConflicts();
        $scopes    = array_column($conflicts, 'scope');

        self::assertContains('setting', $scopes);
        $messages = implode("\n", array_column($conflicts, 'error'));
        self::assertStringContainsString('routing_category_prefix', $messages);
        self::assertStringContainsString('multiple routing settings', $messages);
    }

    public function testFindAllConflictsIgnoresLocalizedAgreement(): void
    {
        $validator = $this->validator([
            'routing_blog_prefix'    => '/blog',
            'routing_blog_prefix_tr' => '/blog',
            'routing_page_prefix'    => '',
        ]);

        self::assertSame([], $validator->findAllConflicts());
    }

    public function testLocaleFirstSegmentBlockedWhileMultiLanguageIsOn(): void
    {
        $validator = $this->validator(['multi_language_content' => '1']);

        self::assertStringContainsString('language code', (string) $validator->getConflictError('/tr', 'routing_shop_prefix'));
        self::assertStringContainsString('language code', (string) $validator->getConflictError('/tr/shop', 'routing_shop_prefix'));
        self::assertStringContainsString('language code', (string) $validator->getPagePathConflictError('/de'));
        // Unsupported locale codes and non-first segments are fine.
        self::assertNull($validator->getConflictError('/en', 'routing_shop_prefix'));
        self::assertNull($validator->getConflictError('/shop/tr', 'routing_shop_prefix'));
    }

    public function testLocaleFirstSegmentAllowedWhileMultiLanguageIsOff(): void
    {
        $validator = $this->validator(['multi_language_content' => '0']);

        self::assertNull($validator->getConflictError('/tr', 'routing_shop_prefix'));
        self::assertNull($validator->getPagePathConflictError('/tr'));
    }

    public function testFindLocaleConflictsOnEnable(): void
    {
        $validator = $this->validator([
            'multi_language_content' => '0',
            'routing_shop_prefix'    => '/tr',
        ]);

        $errors = $validator->findLocaleConflictsOnEnable([]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('routing_shop_prefix', $errors[0]);
    }

    public function testFindLocaleConflictsOnEnableClean(): void
    {
        $validator = $this->validator([
            'multi_language_content' => '0',
            'routing_shop_prefix'    => '/shop',
        ]);

        self::assertSame([], $validator->findLocaleConflictsOnEnable([]));
    }
}
