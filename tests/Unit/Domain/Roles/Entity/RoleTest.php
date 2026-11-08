<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Roles\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Roles\Entity\Role;

final class RoleTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $role = new Role('admin', 'Administrator');

        self::assertSame('admin', $role->getSlug());
        self::assertSame('Administrator', $role->getName());
        self::assertNull($role->getDescription());
        self::assertFalse($role->isSystem());
        self::assertSame([], $role->getPermissionIds());
    }

    public function testSetters(): void
    {
        $role = new Role('editor', 'Editor');

        $role->setSlug('super_editor');
        $role->setName('Super Editor');
        $role->setDescription('Can edit everything');
        $role->setIsSystem(true);
        $role->setPermissionIds([1, 2, 3]);

        self::assertSame('super_editor', $role->getSlug());
        self::assertSame('Super Editor', $role->getName());
        self::assertSame('Can edit everything', $role->getDescription());
        self::assertTrue($role->isSystem());
        self::assertSame([1, 2, 3], $role->getPermissionIds());
    }

    public function testFromRow(): void
    {
        $role = Role::fromRow([
            'id'          => '3',
            'slug'        => 'editor',
            'name'        => 'Editor',
            'description' => 'Content editor',
            'is_system'   => '1',
            'created_at'  => '2024-01-01 00:00:00',
        ], [10, 20]);

        self::assertSame(3, $role->getId());
        self::assertSame('editor', $role->getSlug());
        self::assertSame('Editor', $role->getName());
        self::assertSame('Content editor', $role->getDescription());
        self::assertTrue($role->isSystem());
        self::assertSame([10, 20], $role->getPermissionIds());
        self::assertInstanceOf(\DateTimeImmutable::class, $role->getCreatedAt());
    }

    public function testFromRowDefaults(): void
    {
        $role = Role::fromRow([
            'id'   => '1',
            'slug' => 'x',
            'name' => 'X',
        ]);

        self::assertNull($role->getDescription());
        self::assertFalse($role->isSystem());
        self::assertSame([], $role->getPermissionIds());
        self::assertNull($role->getCreatedAt());
    }
}
