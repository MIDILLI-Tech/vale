<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shared\ValueObject;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\ValueObject\Pagination;

final class PaginationTest extends TestCase
{
    public function testPaginationCalculatesOffset(): void
    {
        $pagination = new Pagination(page: 1, perPage: 20);
        self::assertSame(0, $pagination->offset);

        $pagination = new Pagination(page: 3, perPage: 15);
        self::assertSame(30, $pagination->offset);
    }

    public function testTotalPages(): void
    {
        $pagination = new Pagination(perPage: 10);
        self::assertSame(10, $pagination->totalPages(100));
        self::assertSame(11, $pagination->totalPages(105));
        self::assertSame(0, $pagination->totalPages(0));
    }

    public function testOrderByClause(): void
    {
        $allowed = ['title', 'created_at', 'id'];

        $pagination = new Pagination(sortBy: 'title', sortDir: 'asc');
        self::assertSame(' ORDER BY title ASC', $pagination->orderByClause($allowed));

        $pagination = new Pagination(sortBy: 'created_at', sortDir: 'desc');
        self::assertSame(' ORDER BY created_at DESC', $pagination->orderByClause($allowed));

        // Fallback for not allowed column
        $pagination = new Pagination(sortBy: 'password', sortDir: 'asc');
        self::assertSame(' ORDER BY id DESC', $pagination->orderByClause($allowed, 'id DESC'));

        // Fallback for null sortBy
        $pagination = new Pagination(sortBy: null);
        self::assertSame(' ORDER BY created_at DESC', $pagination->orderByClause($allowed, 'created_at DESC'));

        // Invalid sortDir defaults to DESC
        $pagination = new Pagination(sortBy: 'id', sortDir: 'invalid');
        self::assertSame(' ORDER BY id DESC', $pagination->orderByClause($allowed));
    }

    public function testPaginationDefaults(): void
    {
        $pagination = new Pagination();
        self::assertSame(1, $pagination->page);
        self::assertSame(20, $pagination->perPage);
        self::assertNull($pagination->sortBy);
        self::assertSame('desc', $pagination->sortDir);
        self::assertSame(0, $pagination->offset);
    }
}
