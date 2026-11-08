<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Roles\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Roles\Entity\Permission;

final class PermissionTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $perm = new Permission('manage_posts', 'Manage Posts');

        self::assertSame('manage_posts', $perm->getSlug());
        self::assertSame('Manage Posts', $perm->getName());
        self::assertNull($perm->getGroupName());
    }

    public function testSetters(): void
    {
        $perm = new Permission('edit_posts', 'Edit Posts');

        $perm->setSlug('create_posts');
        $perm->setName('Create Posts');
        $perm->setGroupName('Content');

        self::assertSame('create_posts', $perm->getSlug());
        self::assertSame('Create Posts', $perm->getName());
        self::assertSame('Content', $perm->getGroupName());
    }

    public function testFromRow(): void
    {
        $perm = Permission::fromRow([
            'id'         => '7',
            'slug'       => 'delete_posts',
            'name'       => 'Delete Posts',
            'group_name' => 'Content',
            'created_at' => '2024-01-01 00:00:00',
        ]);

        self::assertSame(7, $perm->getId());
        self::assertSame('delete_posts', $perm->getSlug());
        self::assertSame('Delete Posts', $perm->getName());
        self::assertSame('Content', $perm->getGroupName());
        self::assertInstanceOf(\DateTimeImmutable::class, $perm->getCreatedAt());
    }

    public function testFromRowNullGroupName(): void
    {
        $perm = Permission::fromRow([
            'id'   => '1',
            'slug' => 'x',
            'name' => 'X',
        ]);

        self::assertNull($perm->getGroupName());
        self::assertNull($perm->getCreatedAt());
    }
}
