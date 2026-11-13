<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Media;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Media\MediaOwnerResolver;

final class MediaOwnerResolverTest extends TestCase
{
    public function testPrefersFirstSuperAdminThenFallsBackToAdmin(): void
    {
        $queries = [];
        $db      = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturnCallback(
            static function (string $sql) use (&$queries) {
                $queries[] = $sql;

                // First call (super_admin) finds nothing, second (admin) finds id 4.
                return count($queries) === 2 ? '4' : false;
            }
        );

        self::assertSame(4, (new MediaOwnerResolver($db))->resolve());
        self::assertCount(2, $queries);
    }

    public function testFallsBackToLowestUserIdWhenNoRoleMatches(): void
    {
        $queries = [];
        $db      = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturnCallback(
            static function (string $sql) use (&$queries) {
                $queries[] = $sql;

                return str_contains($sql, 'ORDER BY id ASC LIMIT 1') && !str_contains($sql, 'JOIN') ? '7' : false;
            }
        );

        self::assertSame(7, (new MediaOwnerResolver($db))->resolve());
        self::assertCount(3, $queries);
    }

    public function testReturnsNullWhenThereIsNoUserAtAll(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(false);

        self::assertNull((new MediaOwnerResolver($db))->resolve());
    }

    public function testMappedUserIdIsUsedWhenItExists(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchOne')
            ->with(self::stringContains('WHERE id = ?'))
            ->willReturn('5');

        self::assertSame(5, (new MediaOwnerResolver($db))->resolveMapped(5));
    }

    public function testMappedUserIdFallsBackWhenUserIsGone(): void
    {
        $queries = [];
        $db      = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturnCallback(
            static function (string $sql) use (&$queries) {
                $queries[] = $sql;

                // Missing mapped user, then the super_admin lookup succeeds.
                return count($queries) === 2 ? '3' : false;
            }
        );

        self::assertSame(3, (new MediaOwnerResolver($db))->resolveMapped(9));
    }

    public function testResolutionIsCachedPerInstance(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchOne')->willReturn('2');

        $resolver = new MediaOwnerResolver($db);
        self::assertSame(2, $resolver->resolve());
        self::assertSame(2, $resolver->resolve());
    }
}
