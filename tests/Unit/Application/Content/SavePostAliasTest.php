<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Content;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Content\SavePostUseCase;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class SavePostAliasTest extends TestCase
{
    /** @var array<int, array{string, string, bool, bool}> */
    private array $recordedRedirects = [];

    /** @var string[] */
    private array $deletedAliases = [];

    /** @var array{string, string}|null */
    private ?array $repointed = null;

    /** @var string[] */
    private array $existingManual = [];

    private function buildUseCase(PostRepositoryInterface $posts, ?ContentTypeRepositoryInterface $contentTypes = null, string $redirectGate = '1'): SavePostUseCase
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(0);

        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => match ($key) {
                'routing_page_prefix' => '',
                'revisions_enabled'   => '0',
                default               => $default,
            }
        );

        $redirectDb = $this->createStub(Connection::class);
        $redirectDb->method('fetchFirstColumn')->willReturnCallback(fn (): array => $this->existingManual);
        $redirectDb->method('fetchAssociative')->willReturn(false);
        $redirectDb->method('fetchAllAssociative')->willReturn([]);
        $redirectDb->method('delete')->willReturnCallback(
            function (string $table, array $criteria): int {
                if (($criteria['is_manual'] ?? 0) === 1 && isset($criteria['old_path'])) {
                    $this->deletedAliases[] = (string) $criteria['old_path'];
                }
                return 1;
            }
        );
        $redirectDb->method('update')->willReturnCallback(
            function (string $table, array $data, array $criteria): int {
                if (($criteria['is_manual'] ?? 0) === 1) {
                    $this->repointed = [(string) ($criteria['new_path'] ?? ''), (string) ($data['new_path'] ?? '')];
                }
                return 1;
            }
        );
        $redirectDb->method('insert')->willReturnCallback(
            function (string $table, array $data): int {
                $this->recordedRedirects[] = [
                    (string) $data['old_path'],
                    (string) $data['new_path'],
                    (bool) $data['is_prefix'],
                    (bool) $data['is_manual'],
                ];
                return 1;
            }
        );

        $redirectSettings = $this->createStub(DbalSettingsRepository::class);
        $redirectSettings->method('get')->willReturn($redirectGate);

        $redirects = new RedirectService($redirectDb, $redirectSettings);

        return new SavePostUseCase(
            $posts,
            new SlugService($db),
            $this->createStub(RevisionRepositoryInterface::class),
            $settings,
            $redirects,
            $contentTypes ?? $this->createStub(ContentTypeRepositoryInterface::class),
        );
    }

    private function pageMock(?Post $existing): PostRepositoryInterface
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($existing);
        $posts->method('findPageByPath')->willReturn(null);
        $posts->method('buildPagePath')->willReturnCallback(
            static fn (Post $page): string => (string) $page->getSlug()
        );
        $posts->method('save')->willReturnCallback(
            static function (Post $post): Post {
                if ($post->getId() === null) {
                    $post->setId(7);
                }
                return $post;
            }
        );

        return $posts;
    }

    private function existingPage(): Post
    {
        $post = new Post('About', new Slug('about'), 1);
        $post->setId(7);
        $post->setContentType('page');
        $post->setLanguage('en');

        return $post;
    }

    public function testCreatePageWithAliasesRecordsManualRedirects(): void
    {
        $useCase = $this->buildUseCase($this->pageMock(null));

        $saved = $useCase->execute([
            'title'        => 'About',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'published',
            'aliases'      => 'promo, sale, promo',
        ]);

        self::assertSame(7, $saved->getId());
        self::assertSame(
            [
                ['/promo', '/about', false, true],
                ['/sale', '/about', false, true],
            ],
            $this->recordedRedirects
        );
        self::assertSame([], $this->deletedAliases);
    }

    public function testUpdatePageRemovesDeselectedAliasKeepsSelected(): void
    {
        $this->existingManual = ['/old', '/promo'];
        $useCase              = $this->buildUseCase($this->pageMock($this->existingPage()));

        $useCase->execute([
            'title'        => 'About',
            'slug'         => 'about',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'published',
            'aliases'      => 'promo',
        ], 7);

        self::assertSame(['/old'], $this->deletedAliases);
        self::assertSame([], $this->recordedRedirects);
        self::assertNull($this->repointed);
    }

    public function testSlugChangeRepointsManualAliases(): void
    {
        $useCase = $this->buildUseCase($this->pageMock($this->existingPage()));

        $useCase->execute([
            'title'           => 'About',
            'slug'            => 'about-us',
            'content_type'    => 'page',
            'author_id'       => 1,
            'status'          => 'published',
            'create_redirect' => false,
            'aliases'         => '',
        ], 7);

        self::assertSame(['/about', '/about-us'], $this->repointed);
    }

    public function testInvalidAliasThrows(): void
    {
        $useCase = $this->buildUseCase($this->pageMock($this->existingPage()));

        try {
            $useCase->execute([
                'title'        => 'About',
                'slug'         => 'about',
                'content_type' => 'page',
                'author_id'    => 1,
                'status'       => 'published',
                'aliases'      => 'not a slug!',
            ], 7);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->hasError('aliases'));
        }

        self::assertSame([], $this->recordedRedirects);
    }

    public function testAliasEqualToSlugIsRejected(): void
    {
        $useCase = $this->buildUseCase($this->pageMock($this->existingPage()));

        try {
            $useCase->execute([
                'title'        => 'About',
                'slug'         => 'about',
                'content_type' => 'page',
                'author_id'    => 1,
                'status'       => 'published',
                'aliases'      => 'about',
            ], 7);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->hasError('aliases'));
        }
    }

    public function testTooManyAliasesAreRejected(): void
    {
        $useCase = $this->buildUseCase($this->pageMock($this->existingPage()));

        try {
            $useCase->execute([
                'title'        => 'About',
                'slug'         => 'about',
                'content_type' => 'page',
                'author_id'    => 1,
                'status'       => 'published',
                'aliases'      => 'a1,a2,a3,a4,a5,a6,a7,a8,a9,a10,a11',
            ], 7);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->hasError('aliases'));
        }
    }

    public function testUnknownContentTypeIgnoresAliases(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn(null);
        $posts->method('save')->willReturnCallback(
            static function (Post $post): Post {
                $post->setId(9);
                return $post;
            }
        );

        $useCase = $this->buildUseCase($posts);
        $useCase->execute([
            'title'        => 'Mystery',
            'content_type' => 'mystery',
            'author_id'    => 1,
            'status'       => 'draft',
            'aliases'      => 'promo',
        ]);

        self::assertSame([], $this->recordedRedirects);
    }

    public function testCreatePostWithAliasesRecordsManualRedirects(): void
    {
        $useCase = $this->buildUseCase($this->pageMock(null));

        $useCase->execute([
            'title'        => 'Hello World',
            'content_type' => 'post',
            'author_id'    => 1,
            'status'       => 'published',
            'aliases'      => 'greeting',
        ]);

        self::assertSame(
            [['/blog/greeting', '/blog/hello-world', false, true]],
            $this->recordedRedirects
        );
    }

    public function testCreateCustomTypeWithAliasesUsesTypeBasePath(): void
    {
        $type = new ContentType('Movie', new Slug('movie'));
        $type->setRoutePrefix('d');

        $contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $contentTypes->method('findBySlug')->willReturn($type);

        $useCase = $this->buildUseCase($this->pageMock(null), $contentTypes);

        $useCase->execute([
            'title'        => 'Inception',
            'content_type' => 'movie',
            'author_id'    => 1,
            'status'       => 'published',
            'aliases'      => 'trailer',
        ]);

        self::assertSame(
            [['/d/movie/trailer', '/d/movie/inception', false, true]],
            $this->recordedRedirects
        );
    }

    public function testSlashAliasRejectedForPost(): void
    {
        $post = new Post('Hello', new Slug('hello'), 1);
        $post->setId(8);
        $post->setContentType('post');
        $post->setLanguage('en');

        $useCase = $this->buildUseCase($this->pageMock($post));

        try {
            $useCase->execute([
                'title'        => 'Hello',
                'slug'         => 'hello',
                'content_type' => 'post',
                'author_id'    => 1,
                'status'       => 'published',
                'aliases'      => 'nested/alias',
            ], 8);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->hasError('aliases'));
        }

        self::assertSame([], $this->recordedRedirects);
    }

    public function testPostSlugChangeRepointsManualAliases(): void
    {
        $post = new Post('Hello', new Slug('hello'), 1);
        $post->setId(8);
        $post->setContentType('post');
        $post->setLanguage('en');

        $useCase = $this->buildUseCase($this->pageMock($post));

        $useCase->execute([
            'title'           => 'Hello',
            'slug'            => 'hello-world',
            'content_type'    => 'post',
            'author_id'       => 1,
            'status'          => 'published',
            'create_redirect' => false,
            'aliases'         => '',
        ], 8);

        self::assertSame(['/blog/hello', '/blog/hello-world'], $this->repointed);
    }

    public function testManualAliasRecordedWhenAutoRedirectDisabled(): void
    {
        $useCase = $this->buildUseCase($this->pageMock(null), null, '0');

        $useCase->execute([
            'title'        => 'About',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'published',
            'aliases'      => 'promo',
        ]);

        self::assertSame(
            [['/promo', '/about', false, true]],
            $this->recordedRedirects
        );
    }

    public function testCustomTypeSlugChangeCreatesAutoRedirect(): void
    {
        $type = new ContentType('Movie', new Slug('movie'));
        $type->setRoutePrefix('d');

        $contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $contentTypes->method('findBySlug')->willReturn($type);

        $existing = new Post('Inception', new Slug('inception'), 1);
        $existing->setId(11);
        $existing->setContentType('movie');
        $existing->setLanguage('en');

        $useCase = $this->buildUseCase($this->pageMock($existing), $contentTypes);

        $useCase->execute([
            'title'        => 'Inception',
            'slug'         => 'inception-2',
            'content_type' => 'movie',
            'author_id'    => 1,
            'status'       => 'published',
        ], 11);

        self::assertSame(
            [['/d/movie/inception', '/d/movie/inception-2', false, false]],
            $this->recordedRedirects
        );
    }
}
