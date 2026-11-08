<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shop\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shop\Entity\ProductVote;

final class ProductVoteTest extends TestCase
{
    public function testConstructor(): void
    {
        $vote = new ProductVote(1, 'sess-abc', 4, 5);

        self::assertSame(1, $vote->getProductId());
        self::assertSame('sess-abc', $vote->getSessionId());
        self::assertSame(4, $vote->getRating());
        self::assertSame(5, $vote->getUserId());
        self::assertNull($vote->getCommentId());
        self::assertNull($vote->getReview());
    }

    public function testConstructorNullUserId(): void
    {
        $vote = new ProductVote(1, 'sess', 5);

        self::assertNull($vote->getUserId());
    }

    public function testInvalidRatingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProductVote(1, 'sess', 0);
    }

    public function testInvalidRatingHighThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProductVote(1, 'sess', 6);
    }

    public function testSetRatingInvalidThrows(): void
    {
        $vote = new ProductVote(1, 'sess', 3);
        $this->expectException(\InvalidArgumentException::class);
        $vote->setRating(10);
    }

    public function testSetters(): void
    {
        $vote = new ProductVote(1, 'sess', 3);

        $vote->setCommentId(10);
        $vote->setRating(5);
        $vote->setReview('Great product!');

        self::assertSame(10, $vote->getCommentId());
        self::assertSame(5, $vote->getRating());
        self::assertSame('Great product!', $vote->getReview());
    }

    public function testFromRow(): void
    {
        $vote = ProductVote::fromRow([
            'id'          => '8',
            'product_id'  => '5',
            'session_id'  => 'sess-xyz',
            'rating'      => '4',
            'user_id'     => '3',
            'comment_id'  => '7',
            'review'      => 'Nice',
            'created_at'  => '2024-06-01 12:00:00',
        ]);

        self::assertSame(8, $vote->getId());
        self::assertSame(5, $vote->getProductId());
        self::assertSame('sess-xyz', $vote->getSessionId());
        self::assertSame(4, $vote->getRating());
        self::assertSame(3, $vote->getUserId());
        self::assertSame(7, $vote->getCommentId());
        self::assertSame('Nice', $vote->getReview());
        self::assertInstanceOf(\DateTimeImmutable::class, $vote->getCreatedAt());
    }

    public function testFromRowNullables(): void
    {
        $vote = ProductVote::fromRow([
            'id'         => '1',
            'product_id' => '1',
            'session_id' => 's',
            'rating'     => '3',
        ]);

        self::assertNull($vote->getUserId());
        self::assertNull($vote->getCommentId());
        self::assertNull($vote->getReview());
    }
}
