<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Auth;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Auth\LoginUseCase;
use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Auth\PasswordHasher;
use ValeApp\Infrastructure\Security\RateLimiter;
use ValeApp\Infrastructure\Session\SessionManager;

final class LoginUseCaseTest extends TestCase
{
    public function testDeactivatedUserWithTwoFactorIsRejectedBeforeChallenge(): void
    {
        $user = new User(new Email('jane@example.com'), password_hash('secret123', PASSWORD_BCRYPT), 'Jane');
        $user->setIsActive(false);
        $user->setTwoFactorEnabled(true);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn($user);

        $useCase = new LoginUseCase(
            $users,
            new PasswordHasher(PASSWORD_BCRYPT, 4),
            new SessionManager([]),
            $this->createStub(RoleRepositoryInterface::class),
            new RateLimiter(sys_get_temp_dir() . '/vl_login_test_' . uniqid()),
            $this->createStub(SettingsRepositoryInterface::class),
            ['enabled' => false, 'lifetime' => 0],
        );

        $this->expectException(ValidationException::class);

        try {
            $useCase->execute('jane@example.com', 'secret123');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->getErrors());
            throw $e;
        }
    }

    public function testActiveUserWithTwoFactorReceivesChallenge(): void
    {
        $user = new User(new Email('jane@example.com'), password_hash('secret123', PASSWORD_BCRYPT), 'Jane');
        $user->setIsActive(true);
        $user->setTwoFactorEnabled(true);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn($user);

        $useCase = new LoginUseCase(
            $users,
            new PasswordHasher(PASSWORD_BCRYPT, 4),
            new SessionManager([]),
            $this->createStub(RoleRepositoryInterface::class),
            new RateLimiter(sys_get_temp_dir() . '/vl_login_test_' . uniqid()),
            $this->createStub(SettingsRepositoryInterface::class),
            ['enabled' => false, 'lifetime' => 0],
        );

        $result = $useCase->execute('jane@example.com', 'secret123');

        self::assertTrue($result['two_factor_required']);
    }
}
