<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Comment\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Comment\Entity\Comment;

final class CommentTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $c = new Comment('post', 1, 'John', 'john@test.com', 'Nice article!');

        self::assertSame('post', $c->getEntityType());
        self::assertSame(1, $c->getEntityId());
        self::assertSame('John', $c->getAuthorName());
        self::assertSame('john@test.com', $c->getAuthorEmail());
        self::assertSame('Nice article!', $c->getBody());
        self::assertNull($c->getParentId());
        self::assertNull($c->getAuthorUrl());
        self::assertNull($c->getAuthorIp());
        self::assertNull($c->getUserId());
        self::assertSame('pending', $c->getStatus());
        self::assertFalse($c->isApproved());
        self::assertFalse($c->isVerified());
        self::assertFalse($c->isNotifyReplies());
        self::assertSame('en', $c->getLocale());
    }

    public function testStatusTransitions(): void
    {
        $c = new Comment('post', 1, 'John', 'j@t.com', 'Hi');

        $c->approve();
        self::assertSame('approved', $c->getStatus());
        self::assertTrue($c->isApproved());

        $c->spam();
        self::assertSame('spam', $c->getStatus());

        $c->trash();
        self::assertSame('trash', $c->getStatus());
    }

    public function testSetters(): void
    {
        $c = new Comment('post', 1, 'John', 'j@t.com', 'Hi');

        $c->setParentId(5);
        $c->setAuthorName('Jane');
        $c->setAuthorEmail('jane@test.com');
        $c->setAuthorUrl('https://jane.com');
        $c->setAuthorIp('127.0.0.1');
        $c->setUserId(10);
        $c->setStatus('approved');
        $c->setBody('Updated body');
        $c->setVerified(true);
        $c->setNotifyReplies(true);
        $c->setLocale('tr');

        self::assertSame(5, $c->getParentId());
        self::assertSame('Jane', $c->getAuthorName());
        self::assertSame('jane@test.com', $c->getAuthorEmail());
        self::assertSame('https://jane.com', $c->getAuthorUrl());
        self::assertSame('127.0.0.1', $c->getAuthorIp());
        self::assertSame(10, $c->getUserId());
        self::assertSame('approved', $c->getStatus());
        self::assertSame('Updated body', $c->getBody());
        self::assertTrue($c->isVerified());
        self::assertTrue($c->isNotifyReplies());
        self::assertSame('tr', $c->getLocale());
    }

    public function testFromRow(): void
    {
        $c = Comment::fromRow([
            'id'             => '15',
            'entity_type'    => 'product',
            'entity_id'      => '8',
            'parent_id'      => '3',
            'author_name'    => 'Jane',
            'author_email'   => 'jane@test.com',
            'author_url'     => 'https://jane.com',
            'author_ip'      => '10.0.0.1',
            'user_id'        => '7',
            'body'           => 'Great product!',
            'status'         => 'approved',
            'verified'       => '1',
            'notify_replies' => '1',
            'locale'         => 'tr',
            'created_at'     => '2024-06-01 12:00:00',
        ]);

        self::assertSame(15, $c->getId());
        self::assertSame('product', $c->getEntityType());
        self::assertSame(8, $c->getEntityId());
        self::assertSame(3, $c->getParentId());
        self::assertSame('Jane', $c->getAuthorName());
        self::assertSame('jane@test.com', $c->getAuthorEmail());
        self::assertSame('https://jane.com', $c->getAuthorUrl());
        self::assertSame('10.0.0.1', $c->getAuthorIp());
        self::assertSame(7, $c->getUserId());
        self::assertSame('Great product!', $c->getBody());
        self::assertSame('approved', $c->getStatus());
        self::assertTrue($c->isVerified());
        self::assertTrue($c->isNotifyReplies());
        self::assertSame('tr', $c->getLocale());
        self::assertInstanceOf(\DateTimeImmutable::class, $c->getCreatedAt());
    }

    public function testFromRowDefaults(): void
    {
        $c = Comment::fromRow([
            'id'           => '1',
            'entity_id'    => '1',
            'author_name'  => 'X',
            'author_email' => 'x@x.com',
            'body'         => 'Hi',
        ]);

        self::assertSame('post', $c->getEntityType());
        self::assertNull($c->getParentId());
        self::assertNull($c->getAuthorUrl());
        self::assertNull($c->getAuthorIp());
        self::assertNull($c->getUserId());
        self::assertSame('pending', $c->getStatus());
        self::assertFalse($c->isVerified());
        self::assertSame('en', $c->getLocale());
    }
}
