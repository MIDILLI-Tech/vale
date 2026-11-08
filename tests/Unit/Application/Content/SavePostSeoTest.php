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
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;

final class SavePostSeoTest extends TestCase
{
    private function buildUseCase(PostRepositoryInterface $posts): SavePostUseCase
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(0);

        return new SavePostUseCase(
            $posts,
            new SlugService($db),
            $this->createStub(RevisionRepositoryInterface::class),
            $this->createStub(SettingsRepositoryInterface::class),
            $this->createStub(RedirectService::class),
            $this->createStub(ContentTypeRepositoryInterface::class),
        );
    }

    public function testNestedSeoArrayCopiedToPost(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn(null);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                $post->setId(21);
                self::assertSame('MEtatitle', $post->getMetaTitle());
                self::assertSame('Meta description', $post->getMetaDescription());
                return $post;
            }
        );

        $result = $this->buildUseCase($posts)->execute([
            'title'        => 'Test',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'published',
            'seo'          => [
                'meta_title'       => 'MEtatitle',
                'meta_description' => 'Meta description',
            ],
        ]);

        self::assertSame(21, $result->getId());
    }

    public function testTopLevelMetaTakesPrecedenceOverNestedSeo(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn(null);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                self::assertSame('Explicit', $post->getMetaTitle());
                self::assertSame('Nested desc', $post->getMetaDescription());
                return $post;
            }
        );

        $this->buildUseCase($posts)->execute([
            'title'        => 'Test',
            'content_type' => 'page',
            'author_id'    => 1,
            'status'       => 'published',
            'meta_title'   => 'Explicit',
            'seo'          => [
                'meta_title'       => 'Nested title',
                'meta_description' => 'Nested desc',
            ],
        ]);
    }
}
