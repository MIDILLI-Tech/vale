<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Content\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class PostExpiryTest extends TestCase
{
    private function makePost(string $status = 'draft'): Post
    {
        $post = new Post('Title', new Slug('title'), 1);
        $post->setId(7);
        $post->setStatus($status);
        return $post;
    }

    public function testIsExpiredFalseWithoutExpiry(): void
    {
        self::assertFalse($this->makePost('published')->isExpired());
    }

    public function testIsExpiredFalseForDrafts(): void
    {
        $post = $this->makePost('draft');
        $post->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        self::assertFalse($post->isExpired());
    }

    public function testIsExpiredFalseForFutureExpiry(): void
    {
        $post = $this->makePost('published');
        $post->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        self::assertFalse($post->isExpired());
    }

    public function testIsExpiredTrueForPastExpiry(): void
    {
        $post = $this->makePost('published');
        $post->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        self::assertTrue($post->isExpired());
    }

    public function testIsExpiredTrueForPastExpiryWhenUnlisted(): void
    {
        $post = $this->makePost('unlisted');
        $post->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        self::assertTrue($post->isExpired());
    }

    public function testIsExpiredFalseForFutureExpiryWhenUnlisted(): void
    {
        $post = $this->makePost('unlisted');
        $post->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        self::assertFalse($post->isExpired());
    }

    public function testExpireMovesToExpiredStatusAndClearsSwap(): void
    {
        $post = $this->makePost('published');
        $post->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $post->setSwapPostId(9);

        $post->expire();

        self::assertSame('expired', $post->getStatus());
        self::assertTrue($post->isExpiredStatus());
        self::assertNull($post->getExpiresAt());
        self::assertNull($post->getSwapPostId());
        self::assertFalse($post->hasSwap());
    }

    public function testHasSwap(): void
    {
        $post = $this->makePost();
        self::assertFalse($post->hasSwap());

        $post->setSwapPostId(9);
        self::assertTrue($post->hasSwap());
        self::assertSame(9, $post->getSwapPostId());
    }

    public function testFromRowHydratesExpiryFields(): void
    {
        $post = Post::fromRow([
            'id'           => 7,
            'title'        => 'Title',
            'slug'         => 'title',
            'author_id'    => 1,
            'status'       => 'published',
            'expires_at'   => '2026-11-28 00:00:00',
            'swap_post_id' => 9,
        ]);

        self::assertSame('2026-11-28 00:00:00', $post->getExpiresAt()?->format('Y-m-d H:i:s'));
        self::assertSame(9, $post->getSwapPostId());
    }

    public function testFromRowDefaultsToNull(): void
    {
        $post = Post::fromRow([
            'id'        => 7,
            'title'     => 'Title',
            'slug'      => 'title',
            'author_id' => 1,
        ]);

        self::assertNull($post->getExpiresAt());
        self::assertNull($post->getSwapPostId());
    }
}
