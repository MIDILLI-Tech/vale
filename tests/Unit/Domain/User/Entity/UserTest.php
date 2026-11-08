<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\User\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\User\Entity\User;

final class UserTest extends TestCase
{
    public function testUserCreation(): void
    {
        $email = new Email('test@example.com');
        $user  = new User($email, 'hashed_password', 'John Doe');

        self::assertSame($email, $user->getEmail());
        self::assertSame('hashed_password', $user->getPassword());
        self::assertSame('John Doe', $user->getName());
        self::assertTrue($user->isActive());
        self::assertSame('en', $user->getLocale());
    }

    public function testRoles(): void
    {
        $user = new User(new Email('test@example.com'), 'pass', 'Name');
        $user->setRoles(['admin', 'editor']);

        self::assertTrue($user->hasRole('admin'));
        self::assertTrue($user->hasRole('editor'));
        self::assertFalse($user->hasRole('super_admin'));
        self::assertTrue($user->isAdmin());

        $user->setRoles(['super_admin']);
        self::assertTrue($user->isAdmin());

        $user->setRoles(['editor']);
        self::assertFalse($user->isAdmin());
    }

    public function testFromRow(): void
    {
        $row = [
            'id'                => '1',
            'email'             => 'test@example.com',
            'password'          => 'hashed',
            'name'              => 'John',
            'avatar'            => 'avatar.jpg',
            'locale'            => 'tr',
            'is_active'         => '0',
            'email_verified_at' => '2023-01-01 10:00:00',
            'last_login_at'     => '2023-01-02 12:00:00',
            'created_at'        => '2023-01-01 00:00:00',
            'updated_at'        => '2023-01-01 01:00:00',
        ];

        $user = User::fromRow($row, ['admin']);

        self::assertSame(1, $user->getId());
        self::assertSame('test@example.com', $user->getEmail()->value);
        self::assertSame('avatar.jpg', $user->getAvatar());
        self::assertSame('tr', $user->getLocale());
        self::assertFalse($user->isActive());
        self::assertTrue($user->hasRole('admin'));
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getEmailVerifiedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getLastLoginAt());
    }

    public function testResolvePrimaryRolePrefersHighestPrivilege(): void
    {
        self::assertSame('super_admin', User::resolvePrimaryRole(['editor', 'super_admin']));
        self::assertSame('super_admin', User::resolvePrimaryRole(['admin', 'editor', 'super_admin']));
        self::assertSame('admin', User::resolvePrimaryRole(['subscriber', 'admin', 'author']));
    }

    public function testResolvePrimaryRoleFallsBackToFirstOrSubscriber(): void
    {
        self::assertSame('editor', User::resolvePrimaryRole(['editor', 'author']));
        self::assertSame('shop_manager', User::resolvePrimaryRole(['shop_manager']));
        self::assertSame('subscriber', User::resolvePrimaryRole([]));
    }
}
