<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use ValeApp\App\Controllers\Admin\ContentPaginationTrait;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;

final class ContentPaginationTraitTest extends TestCase
{
    private function host(PostRepositoryInterface $posts): object
    {
        return new class ($posts) {
            use ContentPaginationTrait;

            public function __construct(private PostRepositoryInterface $posts)
            {
            }

            /** @param array<string, mixed> $params @param array<string, mixed> $filters @return array{object, bool, int} */
            public function paginate(array $params, array $filters, int $total): array
            {
                return $this->resolvePagination($params, $filters, $total);
            }
        };
    }

    public function testSmallCatalogStaysClientSideWithoutSearchBody(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);

        [$pagination, $clientSide, $total] = $this->host($posts)->paginate([], [], 5);

        self::assertTrue($clientSide);
        self::assertSame(5, $total);
        self::assertSame(1000000, $pagination->perPage);
    }

    public function testSearchBodyForcesServerSideOnSmallCatalog(): void
    {
        $counted = [];
        $posts   = $this->createStub(PostRepositoryInterface::class);
        $posts->method('count')->willReturnCallback(
            static function (array $filters) use (&$counted): int {
                $counted[] = $filters;
                return 1;
            }
        );

        $filters                           = ['content_type' => 'post'];
        [$pagination, $clientSide, $total] = $this->host($posts)->paginate(
            ['search' => 'kamelya', 'search_body' => '1'],
            $filters,
            5,
        );

        self::assertFalse($clientSide);
        self::assertSame(1, $total);
        self::assertSame(20, $pagination->perPage);
        self::assertCount(1, $counted);
        self::assertSame('kamelya', $counted[0]['search']);
        self::assertTrue($counted[0]['search_body']);
    }

    public function testSearchBodyAloneForcesServerSide(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('count')->willReturn(5);

        [$pagination, $clientSide, $total] = $this->host($posts)->paginate(['search_body' => '1'], [], 5);

        self::assertFalse($clientSide);
        self::assertSame(5, $total);
        self::assertSame(20, $pagination->perPage);
    }
}
