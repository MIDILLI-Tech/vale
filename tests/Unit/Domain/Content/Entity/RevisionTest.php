<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Content\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Content\Entity\Revision;

final class RevisionTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $rev = new Revision(1, 2, 'Title', '<p>Body</p>', 'Excerpt', 3);

        self::assertSame(1, $rev->getPostId());
        self::assertSame(2, $rev->getAuthorId());
        self::assertSame('Title', $rev->getTitle());
        self::assertSame('<p>Body</p>', $rev->getBody());
        self::assertSame('Excerpt', $rev->getExcerpt());
        self::assertSame(3, $rev->getRevisionNumber());
        self::assertNull($rev->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $rev->getCreatedAt());
    }

    public function testSetId(): void
    {
        $rev = new Revision(1, 2, 'Title', null, null, 1);
        $rev->setId(99);

        self::assertSame(99, $rev->getId());
    }

    public function testFromRow(): void
    {
        $rev = Revision::fromRow([
            'id'               => '15',
            'post_id'          => '7',
            'author_id'        => '3',
            'title'            => 'Updated',
            'body'             => '<p>New</p>',
            'excerpt'          => 'New excerpt',
            'revision_number'  => '5',
            'created_at'       => '2024-06-01 12:00:00',
        ]);

        self::assertSame(15, $rev->getId());
        self::assertSame(7, $rev->getPostId());
        self::assertSame(3, $rev->getAuthorId());
        self::assertSame('Updated', $rev->getTitle());
        self::assertSame('<p>New</p>', $rev->getBody());
        self::assertSame('New excerpt', $rev->getExcerpt());
        self::assertSame(5, $rev->getRevisionNumber());
        self::assertInstanceOf(\DateTimeImmutable::class, $rev->getCreatedAt());
    }

    public function testFromRowWithNullBody(): void
    {
        $rev = Revision::fromRow([
            'id'              => '1',
            'post_id'         => '1',
            'author_id'       => '1',
            'title'           => 'Draft',
            'body'            => null,
            'excerpt'         => null,
            'revision_number' => '1',
        ]);

        self::assertNull($rev->getBody());
        self::assertNull($rev->getExcerpt());
        self::assertInstanceOf(\DateTimeImmutable::class, $rev->getCreatedAt());
    }
}
