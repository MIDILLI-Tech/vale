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
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;

final class SavePostRoutingTest extends TestCase
{
    /** @param array<string, string> $settings */
    private function buildUseCase(array $settings, array $types, PostRepositoryInterface $posts): SavePostUseCase
    {
        $settingsRepo = $this->createStub(SettingsRepositoryInterface::class);
        $settingsRepo->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $settings[$key] ?? $default,
        );

        $typeRepo = $this->createStub(ContentTypeRepositoryInterface::class);
        $typeRepo->method('findAll')->willReturn($types);
        $typeRepo->method('findActiveCustom')->willReturn($types);

        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(0);

        return new SavePostUseCase(
            $posts,
            new SlugService($db),
            $this->createStub(RevisionRepositoryInterface::class),
            $settingsRepo,
            $this->createStub(RedirectService::class),
            $this->createStub(ContentTypeRepositoryInterface::class),
            new RoutingValidator($settingsRepo, $typeRepo, $posts, []),
        );
    }

    public function testCreatePageBlockedWhenSlugMatchesCptBasePath(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findAll')->willReturn([]);
        $posts->expects(self::never())->method('save');

        $useCase = $this->buildUseCase(
            ['routing_page_prefix' => ''],
            [new ContentType('Test', new Slug('test'))],
            $posts,
        );

        try {
            $useCase->execute([
                'title'        => 'Test',
                'slug'         => 'test',
                'body'         => str_repeat('x', 10000),
                'content_type' => 'page',
                'author_id'    => 1,
                'status'       => 'draft',
            ]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->hasError('slug'));
            self::assertStringContainsString('content type', (string) json_encode($e->getErrors()));
        }
    }

    public function testCreatePageAllowedWhenPagePrefixDisambiguates(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findAll')->willReturn([]);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                $post->setId(7);
                return $post;
            },
        );

        $useCase = $this->buildUseCase(
            ['routing_page_prefix' => '/pages'],
            [new ContentType('Test', new Slug('test'))],
            $posts,
        );

        $result = $useCase->execute([
            'title'        => 'Test',
            'slug'         => 'test',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'draft',
        ]);

        self::assertSame(7, $result->getId());
    }

    public function testCreatePostSlugUnaffectedByPageCptRules(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                $post->setId(8);
                return $post;
            },
        );

        $useCase = $this->buildUseCase(
            ['routing_page_prefix' => ''],
            [new ContentType('Test', new Slug('test'))],
            $posts,
        );

        // Blog posts live under /blog — a CPT at /test never collides.
        $result = $useCase->execute([
            'title'        => 'Test',
            'slug'         => 'test',
            'content_type' => 'post',
            'author_id'    => 1,
            'status'       => 'draft',
        ]);

        self::assertSame(8, $result->getId());
    }

    public function testPageSlugErrorNamesPageUrlNotSettingKey(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findAll')->willReturn([]);
        $posts->expects(self::never())->method('save');

        $useCase = $this->buildUseCase([], [], $posts);

        try {
            $useCase->execute([
                'title'        => 'Reset',
                'slug'         => 'reset-password',
                'content_type' => 'page',
                'author_id'    => 1,
                'status'       => 'draft',
            ]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->hasError('slug'));
            $errors = (string) json_encode($e->getErrors());
            self::assertStringContainsString('/reset-password', $errors);
            self::assertStringContainsString('Reset Password', $errors);
            self::assertStringNotContainsString('routing_reset_password_prefix', $errors);
        }
    }

    public function testPageSlugErrorForReservedRoute(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findAll')->willReturn([]);
        $posts->expects(self::never())->method('save');

        $useCase = $this->buildUseCase([], [], $posts);

        try {
            $useCase->execute([
                'title'        => 'Admin',
                'slug'         => 'admin',
                'content_type' => 'page',
                'author_id'    => 1,
                'status'       => 'draft',
            ]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->hasError('slug'));
            self::assertStringContainsString('reserved for system use', (string) json_encode($e->getErrors()));
        }
    }
}
