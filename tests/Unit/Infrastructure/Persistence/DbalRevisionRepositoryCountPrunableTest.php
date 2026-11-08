<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Persistence\DbalRevisionRepository;

final class DbalRevisionRepositoryCountPrunableTest extends TestCase
{
    public function testCountsSurplusRevisionsPerPost(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([
            ['post_id' => 1, 'total' => 15],
            ['post_id' => 2, 'total' => 5],
            ['post_id' => 3, 'total' => 10],
        ]);

        $repo = new DbalRevisionRepository($db);

        self::assertSame(5, $repo->countPrunable(10));
    }

    public function testDeleteOldestUsesValidSqlWhitespace(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetchFirstColumn')
            ->with(
                'SELECT id FROM vl_revisions '
                . 'WHERE post_id = ? '
                . 'ORDER BY revision_number DESC '
                . 'LIMIT ?',
                [14, 10],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            )
            ->willReturn([21, 20]);
        $db->expects($this->once())
            ->method('executeStatement')
            ->with(
                'DELETE FROM vl_revisions '
                . 'WHERE post_id = ? AND id NOT IN (?,?)',
                [14, 21, 20],
                [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
            )
            ->willReturn(1);

        $repo = new DbalRevisionRepository($db);

        $repo->deleteOldestByPostId(14, 10);
    }

    public function testUsesHighestRevisionNumberForNextRevision(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(11);

        $repo = new DbalRevisionRepository($db);

        self::assertSame(11, $repo->nextRevisionNumber(7));
    }

    public function testReturnsZeroWhenKeepCountBelowOne(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAllAssociative');

        $repo = new DbalRevisionRepository($db);

        self::assertSame(0, $repo->countPrunable(0));
    }

    public function testCountPrunableFiltersByContentType(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                'SELECT r.post_id, COUNT(*) AS total FROM vl_revisions r'
                . ' INNER JOIN vl_posts p ON p.id = r.post_id WHERE p.content_type = ?'
                . ' GROUP BY r.post_id',
                ['page'],
                [ParameterType::STRING],
            )
            ->willReturn([['post_id' => 7, 'total' => 15]]);

        $repo = new DbalRevisionRepository($db);

        self::assertSame(5, $repo->countPrunable(10, 'page'));
    }

    public function testPruneAllFiltersByContentType(): void
    {
        $db      = $this->createMock(Connection::class);
        $seenSql = [];
        $db->expects($this->exactly(2))
            ->method('fetchFirstColumn')
            ->willReturnCallback(
                static function (string $sql) use (&$seenSql): array {
                    $seenSql[] = $sql;

                    return str_contains($sql, 'INNER JOIN') ? [7] : [21, 20];
                }
            );
        $counts = [15, 10];
        $db->method('fetchOne')->willReturnCallback(
            static function () use (&$counts): int {
                return array_shift($counts);
            }
        );
        $db->method('executeStatement')->willReturn(5);

        $repo = new DbalRevisionRepository($db);

        self::assertSame(5, $repo->pruneAll(10, 'page'));
        self::assertContains(
            'SELECT DISTINCT r.post_id FROM vl_revisions r'
            . ' INNER JOIN vl_posts p ON p.id = r.post_id'
            . ' WHERE p.content_type = ?',
            $seenSql
        );
    }

    public function testPruneAllUnknownContentTypeDeletesNothing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetchFirstColumn')->willReturn([]);
        $db->expects($this->never())->method('executeStatement');

        $repo = new DbalRevisionRepository($db);

        self::assertSame(0, $repo->pruneAll(10, 'no-such-type'));
    }
}
