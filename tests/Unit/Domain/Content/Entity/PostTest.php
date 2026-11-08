<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Content\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class PostTest extends TestCase
{
    public function testPostCreation(): void
    {
        $slug = new Slug('my-post');
        $post = new Post('My Post', $slug, 1);

        self::assertSame('My Post', $post->getTitle());
        self::assertSame($slug, $post->getSlug());
        self::assertSame(1, $post->getAuthorId());
        self::assertSame('draft', $post->getStatus());
        self::assertTrue($post->isDraft());
    }

    public function testStatusTransitions(): void
    {
        $post = new Post('Title', new Slug('slug'), 1);

        self::assertNull($post->getPublishedAt());

        $post->publish();
        self::assertTrue($post->isPublished());
        self::assertInstanceOf(\DateTimeImmutable::class, $post->getPublishedAt());

        $post->makeUnlisted();
        self::assertTrue($post->isUnlisted());

        $post->trash();
        self::assertSame('trash', $post->getStatus());
    }

    public function testFromRow(): void
    {
        $row = [
            'id'           => '10',
            'title'        => 'Row Post',
            'slug'         => 'row-post',
            'author_id'    => '2',
            'status'       => 'published',
            'published_at' => '2023-05-01 00:00:00'
        ];

        $post = Post::fromRow($row, [1, 2], [3]);

        self::assertSame(10, $post->getId());
        self::assertSame('Row Post', $post->getTitle());
        self::assertSame(2, $post->getAuthorId());
        self::assertTrue($post->isPublished());
        self::assertSame([1, 2], $post->getCategoryIds());
        self::assertSame([3], $post->getTagIds());
        self::assertSame(0, $post->getViewCount());
    }

    public function testFromRowHydratesViewCount(): void
    {
        $row = [
            'id'         => '10',
            'title'      => 'Row Post',
            'slug'       => 'row-post',
            'author_id'  => '2',
            'status'     => 'published',
            'view_count' => '1234',
        ];

        $post = Post::fromRow($row);

        self::assertSame(1234, $post->getViewCount());
    }

    public function testHideTitleDefaultsToFalse(): void
    {
        $post = new Post('Title', new Slug('slug'), 1);

        self::assertFalse($post->isHideTitle());
    }

    public function testFromRowHydratesHideTitle(): void
    {
        $row = [
            'id'         => '11',
            'title'      => 'Hidden Title Page',
            'slug'       => 'hidden-title-page',
            'author_id'  => '2',
            'hide_title' => '1',
        ];

        $post = Post::fromRow($row);

        self::assertTrue($post->isHideTitle());

        $post->setHideTitle(false);

        self::assertFalse($post->isHideTitle());
    }

    public function testFromRowHideTitleDefaultsToFalse(): void
    {
        $post = Post::fromRow([
            'id'        => '12',
            'title'     => 'Plain Page',
            'slug'      => 'plain-page',
            'author_id' => '2',
        ]);

        self::assertFalse($post->isHideTitle());
    }
}
