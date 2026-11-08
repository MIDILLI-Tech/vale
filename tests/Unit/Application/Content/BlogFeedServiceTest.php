<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Content;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Content\BlogFeedService;
use ValeApp\Application\Form\FormAccessService;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Content\ShortcodeProcessor;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\CsrfManager;
use ValeApp\Infrastructure\Security\Encrypter;
use ValeApp\Infrastructure\Session\SessionManager;

final class BlogFeedServiceTest extends TestCase
{
    /**
     * @param array<string, string> $cache
     */
    private function settingsWithCache(array $cache): DbalSettingsRepository
    {
        $repo = new DbalSettingsRepository(
            $this->createStub(Connection::class),
            $this->createStub(Encrypter::class),
        );

        $reflection = new \ReflectionClass($repo);
        $cacheProp  = $reflection->getProperty('cache');
        $cacheProp->setValue($repo, $cache);

        $loadedProp = $reflection->getProperty('loaded');
        $loadedProp->setValue($repo, true);

        return $repo;
    }

    public function testGenerateRssFiltersByLocaleWhenMultiLanguageEnabled(): void
    {
        $settings = $this->settingsWithCache([
            'multi_language_content'          => '1',
            'i18n_display_originals_fallback' => '1',
            'default_locale'                  => 'en',
            'content_languages'               => 'en,tr',
            'routing_blog_prefix'             => '/blog',
        ]);

        $posts = $this->createMock(\ValeApp\Domain\Content\Repository\PostRepositoryInterface::class);
        $posts->expects(self::once())
            ->method('findAll')
            ->with(
                self::isInstanceOf(Pagination::class),
                self::callback(static function (array $filters): bool {
                    return ($filters['status'] ?? null)             === 'published'
                        && ($filters['content_type'] ?? null)       === 'post'
                        && ($filters['language'] ?? null)           === 'tr'
                        && ($filters['language_fallback'] ?? false) === true;
                }),
            )
            ->willReturn([]);

        $media = $this->createStub(MediaRepositoryInterface::class);

        $service = new BlogFeedService($posts, $media, 'http://example.test', 'Blog', settings: $settings);
        $xml     = $service->setLocale('tr')->generateRss();

        self::assertStringContainsString('<language>tr</language>', $xml);
        self::assertStringContainsString('hreflang="en"', $xml);
        self::assertStringContainsString('hreflang="tr"', $xml);
    }

    public function testGenerateRssDoesNotFilterLanguageWhenMultiLanguageDisabled(): void
    {
        $settings = $this->settingsWithCache([
            'multi_language_content' => '0',
            'default_locale'         => 'en',
            'routing_blog_prefix'    => '/blog',
        ]);

        $posts = $this->createMock(\ValeApp\Domain\Content\Repository\PostRepositoryInterface::class);
        $posts->expects(self::once())
            ->method('findAll')
            ->with(
                self::isInstanceOf(Pagination::class),
                self::callback(static function (array $filters): bool {
                    return !array_key_exists('language', $filters);
                }),
            )
            ->willReturn([]);

        $media = $this->createStub(MediaRepositoryInterface::class);

        $service = new BlogFeedService($posts, $media, 'http://example.test', 'Blog', settings: $settings);
        $xml     = $service->setLocale('tr')->generateRss();

        self::assertStringNotContainsString('hreflang=', $xml);
        self::assertStringContainsString('<language>en</language>', $xml);
    }

    public function testGenerateRssSkipsLocaleFilterWhenDisabledForCustomType(): void
    {
        $settings = $this->settingsWithCache([
            'multi_language_content'          => '1',
            'i18n_display_originals_fallback' => '1',
            'default_locale'                  => 'en',
            'content_languages'               => 'en,tr',
            'routing_blog_prefix'             => '/blog',
        ]);

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->expects(self::once())
            ->method('findAll')
            ->with(
                self::isInstanceOf(Pagination::class),
                self::callback(static function (array $filters): bool {
                    return ($filters['content_type'] ?? null) === 'portfolio'
                        && !array_key_exists('language', $filters);
                }),
            )
            ->willReturn([]);

        $media = $this->createStub(MediaRepositoryInterface::class);

        $service = new BlogFeedService($posts, $media, 'http://example.test', 'Blog', settings: $settings);
        $xml     = $service
            ->setFeedSlug('portfolio')
            ->setRoutePrefix('/portfolio')
            ->setLocaleFilterEnabled(false)
            ->setLocale('tr')
            ->generateRss();

        self::assertStringNotContainsString('hreflang=', $xml);
        self::assertStringContainsString('<language>en</language>', $xml);
        self::assertStringNotContainsString('[TR]', $xml);
    }

    public function testGenerateRssStripsShortcodesFromBody(): void
    {
        $settings = $this->settingsWithCache([
            'multi_language_content' => '0',
            'default_locale'         => 'en',
            'routing_blog_prefix'    => '/blog',
        ]);

        $post = new Post('Hello world', new Slug('hello-world'), 1);
        $post->publish();
        $post->setBody('<p>Intro</p><p>[form slug="test-en"]</p><p>Outro</p>');

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findAll')->willReturn([$post]);

        $media = $this->createStub(MediaRepositoryInterface::class);

        $shortcodes = new ShortcodeProcessor(
            $this->createStub(FormRepositoryInterface::class),
            $this->createStub(CsrfManager::class),
            $this->createStub(SessionManager::class),
            $posts,
            $media,
            $this->createStub(ProductRepositoryInterface::class),
            $settings,
            new FormAccessService(
                $this->createStub(SessionManager::class),
                $this->createStub(SubscriberRepositoryInterface::class),
                $settings,
            ),
            new FormCaptchaService(
                $this->createStub(FormRepositoryInterface::class),
                $settings,
            ),
        );

        $service = new BlogFeedService($posts, $media, 'http://example.test', 'Blog', settings: $settings, shortcodes: $shortcodes);
        $xml     = $service->generateRss();

        self::assertStringContainsString('<title>Hello world</title>', $xml);
        self::assertStringNotContainsString('[form slug="test-en"]', $xml);
        self::assertStringContainsString('Intro', $xml);
        self::assertStringContainsString('Outro', $xml);
        self::assertStringContainsString('/blog/hello-world', $xml);
    }

    public function testGenerateRssPassesTagFilterToRepository(): void
    {
        $settings = $this->settingsWithCache([
            'multi_language_content' => '0',
            'default_locale'         => 'en',
            'routing_blog_prefix'    => '/blog',
        ]);

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->expects(self::once())
            ->method('findAll')
            ->with(
                self::isInstanceOf(Pagination::class),
                self::callback(static function (array $filters): bool {
                    return ($filters['status'] ?? null)       === 'published'
                        && ($filters['content_type'] ?? null) === 'post'
                        && ($filters['tag'] ?? null)          === 'php';
                }),
            )
            ->willReturn([]);

        $media = $this->createStub(MediaRepositoryInterface::class);

        $service = new BlogFeedService($posts, $media, 'http://example.test', 'Blog', settings: $settings);
        $service->setTag('php')->generateRss();
    }

    public function testGenerateRssPassesCategoryAndTagFiltersTogether(): void
    {
        $settings = $this->settingsWithCache([
            'multi_language_content' => '0',
            'default_locale'         => 'en',
            'routing_blog_prefix'    => '/blog',
        ]);

        $lookedUp = [];
        $posts    = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findAll')->willReturnCallback(
            static function (Pagination $pagination, array $filters) use (&$lookedUp): array {
                $lookedUp[] = $filters;
                return [];
            }
        );

        $media = $this->createStub(MediaRepositoryInterface::class);

        $service = new BlogFeedService($posts, $media, 'http://example.test', 'Blog', settings: $settings);
        $service->setCategory('Vale')->setTag('News')->generateRss();

        self::assertCount(1, $lookedUp);
        self::assertSame('Vale', $lookedUp[0]['category'] ?? null);
        self::assertSame('News', $lookedUp[0]['tag'] ?? null);
    }

    public function testGenerateRssResetsCategoryAndTagStateAfterRun(): void
    {
        $settings = $this->settingsWithCache([
            'multi_language_content' => '0',
            'default_locale'         => 'en',
            'routing_blog_prefix'    => '/blog',
        ]);

        $lookedUp = [];
        $posts    = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findAll')->willReturnCallback(
            static function (Pagination $pagination, array $filters) use (&$lookedUp): array {
                $lookedUp[] = $filters;
                return [];
            }
        );

        $media = $this->createStub(MediaRepositoryInterface::class);

        $service = new BlogFeedService($posts, $media, 'http://example.test', 'Blog', settings: $settings);
        $service->setCategory('Vale')->setTag('News')->generateRss();
        $service->generateRss();

        self::assertCount(2, $lookedUp);
        self::assertArrayHasKey('category', $lookedUp[0]);
        self::assertArrayHasKey('tag', $lookedUp[0]);
        self::assertArrayNotHasKey('category', $lookedUp[1]);
        self::assertArrayNotHasKey('tag', $lookedUp[1]);
    }
}
