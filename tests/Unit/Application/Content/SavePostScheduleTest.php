<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Content;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Content\SavePostUseCase;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;

final class SavePostScheduleTest extends TestCase
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

    private function existingPost(string $status): Post
    {
        $post = new Post('Mevcut', new Slug('mevcut'), 1);
        $post->setId(5);
        $post->setContentType('post');
        $post->setStatus($status);
        return $post;
    }

    public function testDraftWithScheduleDateBecomesScheduled(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                self::assertSame('scheduled', $post->getStatus());
                self::assertNotNull($post->getPublishedAt());
                return $post;
            }
        );
        $this->posts = $posts;

        $this->buildUseCase()->execute([
            'title'         => 'Yakında',
            'content_type'  => 'post',
            'author_id'     => 1,
            'status'        => 'draft',
            'set_schedule'  => '1',
            'published_at'  => (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i'),
        ]);
    }

    public function testUnlistedWithScheduleDateStaysUnlistedForGoPublic(): void
    {
        $existing = $this->existingPost('draft');

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($existing);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                self::assertSame('unlisted', $post->getStatus());
                self::assertNotNull($post->getPublishedAt());
                self::assertTrue($post->getPublishedAt() > new \DateTimeImmutable());
                return $post;
            }
        );
        $this->posts = $posts;

        $this->buildUseCase()->execute([
            'title'         => 'Önizleme',
            'slug'          => 'mevcut',
            'content_type'  => 'post',
            'author_id'     => 1,
            'status'        => 'unlisted',
            'set_schedule'  => '1',
            'published_at'  => (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i'),
        ], 5);
    }

    public function testPublishedIgnoresScheduleDate(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                self::assertSame('published', $post->getStatus());
                return $post;
            }
        );
        $this->posts = $posts;

        $this->buildUseCase()->execute([
            'title'         => 'Yayında',
            'content_type'  => 'post',
            'author_id'     => 1,
            'status'        => 'published',
            'set_schedule'  => '1',
            'published_at'  => (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i'),
        ]);
    }

    public function testInvalidScheduleDateIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->buildUseCase()->execute([
            'title'         => 'Bozuk',
            'content_type'  => 'post',
            'author_id'     => 1,
            'status'        => 'draft',
            'set_schedule'  => '1',
            'published_at'  => 'not-a-date',
        ]);
    }

    public function testClearScheduleDropsPendingMarkerExpiryAndSwap(): void
    {
        $existing = $this->existingPost('unlisted');
        $existing->setPublishedAt(new \DateTimeImmutable('+2 days'));
        $existing->setExpiresAt(new \DateTimeImmutable('+30 days'));
        $existing->setSwapPostId(9);

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($existing);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                self::assertSame('unlisted', $post->getStatus());
                self::assertNull($post->getPublishedAt());
                self::assertNull($post->getExpiresAt());
                self::assertNull($post->getSwapPostId());
                return $post;
            }
        );
        $this->posts = $posts;

        $this->buildUseCase()->execute([
            'title'           => 'Vazgeçildi',
            'slug'            => 'mevcut',
            'content_type'    => 'post',
            'author_id'       => 1,
            'status'          => 'unlisted',
            'clear_schedule'  => '1',
        ], 5);
    }

    public function testClearScheduleKeepsHistoricalPublishDate(): void
    {
        $existing = $this->existingPost('published');
        $existing->setPublishedAt(new \DateTimeImmutable('-2 days'));

        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($existing);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                self::assertSame('published', $post->getStatus());
                self::assertNotNull($post->getPublishedAt());
                return $post;
            }
        );
        $this->posts = $posts;

        $this->buildUseCase()->execute([
            'title'           => 'Arşivlik',
            'slug'            => 'mevcut',
            'content_type'    => 'post',
            'author_id'       => 1,
            'status'          => 'published',
            'clear_schedule'  => '1',
        ], 5);
    }

    public function testLegacyDirectScheduledStatusStillWorks(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                self::assertSame('scheduled', $post->getStatus());
                self::assertNotNull($post->getPublishedAt());
                return $post;
            }
        );
        $this->posts = $posts;

        $this->buildUseCase()->execute([
            'title'         => 'API',
            'content_type'  => 'post',
            'author_id'     => 1,
            'status'        => 'scheduled',
            'published_at'  => (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i'),
        ]);
    }

    public function testLegacyScheduledWithoutDateIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->buildUseCase()->execute([
            'title'        => 'API Bozuk',
            'content_type' => 'post',
            'author_id'    => 1,
            'status'       => 'scheduled',
        ]);
    }
}
