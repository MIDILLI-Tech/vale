<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shared\ValueObject;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class SlugTest extends TestCase
{
    public function testValidSlug(): void
    {
        $slug = new Slug('my-cool-post');
        self::assertSame('my-cool-post', $slug->value);
        self::assertSame('my-cool-post', (string) $slug);
    }

    public function testHierarchicalSlug(): void
    {
        $slug = new Slug('category/sub-category/post');
        self::assertSame('category/sub-category/post', $slug->value);
    }

    public function testEmptySlugThrowsException(): void
    {
        $this->expectException(DomainException::class);
        new Slug('   ');
    }

    public function testInvalidFormatThrowsException(): void
    {
        $this->expectException(DomainException::class);
        new Slug('Invalid Slug!');
    }

    public function testFromString(): void
    {
        $slug = Slug::fromString(' My Cool Post! ');
        self::assertSame('my-cool-post', $slug->value);

        $slug2 = Slug::fromString('   ');
        self::assertSame('untitled', $slug2->value);
    }

    public function testWithSuffix(): void
    {
        $slug     = new Slug('post');
        $suffixed = $slug->withSuffix(2);
        self::assertSame('post-2', $suffixed->value);
    }

    public function testEquals(): void
    {
        $slug1 = new Slug('post');
        $slug2 = new Slug('post');
        $slug3 = new Slug('other');

        self::assertTrue($slug1->equals($slug2));
        self::assertFalse($slug1->equals($slug3));
    }
}
