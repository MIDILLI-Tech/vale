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

final class SavePostSwapTest extends TestCase
{
    private $posts;

    private $contentTypes;

    private $db;

    protected function setUp(): void
    {
        $this->posts        = $this->createStub(PostRepositoryInterface::class);
        $this->contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $this->db           = $this->createStub(Connection::class);
        $this->db->method('fetchOne')->willReturn(0);
    }

    private function buildUseCase(): SavePostUseCase
    {
        return new SavePostUseCase(
            $this->posts,
            new SlugService($this->db),
            $this->createStub(RevisionRepositoryInterface::class),
            $this->createStub(SettingsRepositoryInterface::class),
            $this->createStub(RedirectService::class),
            $this->contentTypes,
        );
    }

    private function livePage(int $id = 5, string $slug = 'kampanya'): Post
    {
        $post = new Post('Kampanya', new Slug($slug), 1);
        $post->setId($id);
        $post->setContentType('page');
        $post->setStatus('published');
        $post->setPublishedAt(new \DateTimeImmutable('2026-11-01 10:00:00'));
        return $post;
    }

    public function testCreateStoresExpiry(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn(null);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                $post->setId(11);
                self::assertSame('2026-11-28 00:00', $post->getExpiresAt()?->format('Y-m-d H:i'));
                self::assertNull($post->getSwapPostId());
                return $post;
            }
        );
        $this->posts = $posts;

        $result = $this->buildUseCase()->execute([
            'title'        => 'Duyuru',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'published',
            'expires_at'   => '2026-11-28T00:00',
        ]);

        self::assertSame(11, $result->getId());
    }

    public function testCreateLinksSwapPartnerOnPageByDefault(): void
    {
        $partner = $this->livePage();

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturnMap([[5, $partner]]);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                $post->setId(12);
                self::assertSame(5, $post->getSwapPostId());
                return $post;
            }
        );
        $this->posts = $posts;

        $this->buildUseCase()->execute([
            'title'        => 'BF',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'scheduled',
            'published_at' => '2026-11-28T00:00',
            'swap_post_id' => 5,
        ]);
    }

    public function testSwapRequiresSupportFlagForPosts(): void
    {
        $type = new ContentType('Blog', new Slug('post'));
        $type->setSupports(['title', 'editor']);
        $this->contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $this->contentTypes->method('findBySlug')->willReturn($type);

        $this->expectException(ValidationException::class);
        $this->buildUseCase()->execute([
            'title'        => 'X',
            'content_type' => 'post',
            'author_id'    => 1,
            'status'       => 'draft',
            'swap_post_id' => 5,
        ]);
    }

    public function testSwapRejectsItself(): void
    {
        $existing = $this->livePage(7);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($existing);
        $this->posts = $posts;

        $this->expectException(ValidationException::class);
        $this->buildUseCase()->execute([
            'title'        => 'Kampanya',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'published',
            'swap_post_id' => 7,
        ], 7);
    }

    public function testSwapRejectsMissingPartner(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturnMap([
            [8, $this->livePage(8)],
            [999, null],
        ]);
        $this->posts = $posts;

        $this->expectException(ValidationException::class);
        $this->buildUseCase()->execute([
            'title'        => 'Kampanya',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'published',
            'swap_post_id' => 999,
        ], 8);
    }

    public function testSwapRejectsTrashedPartner(): void
    {
        $partner = $this->livePage(5);
        $partner->trash();

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturnMap([
            [6, $this->livePage(6)],
            [5, $partner],
        ]);
        $this->posts = $posts;

        $this->expectException(ValidationException::class);
        $this->buildUseCase()->execute([
            'title'        => 'Kampanya',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'published',
            'swap_post_id' => 5,
        ], 6);
    }

    public function testSwapRejectsTypeMismatch(): void
    {
        $partner = new Post('Yazi', new Slug('yazi'), 1);
        $partner->setId(5);
        $partner->setContentType('post');
        $partner->setStatus('published');

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturnMap([
            [6, $this->livePage(6)],
            [5, $partner],
        ]);
        $this->posts = $posts;

        $this->expectException(ValidationException::class);
        $this->buildUseCase()->execute([
            'title'        => 'Kampanya',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'published',
            'swap_post_id' => 5,
        ], 6);
    }

    public function testExpiryBeforePublishIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->buildUseCase()->execute([
            'title'        => 'Duyuru',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'scheduled',
            'published_at' => '2026-11-28T00:00',
            'expires_at'   => '2026-11-01T00:00',
        ]);
    }
}
