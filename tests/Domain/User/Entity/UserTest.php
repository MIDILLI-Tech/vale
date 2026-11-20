<?php

declare(strict_types=1);

namespace ValeApp\Tests\Domain\User\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\User\Entity\User;

final class UserTest extends TestCase
{
    public function testPublicNameFallsBackToAccountName(): void
    {
        $user = new User(new Email('writer@example.com'), 'hash', 'Erhan Kaya');

        self::assertSame('Erhan Kaya', $user->getPublicName());

        $user->setDisplayName('   ');
        self::assertSame('Erhan Kaya', $user->getPublicName());

        $user->setDisplayName('Kalem');
        self::assertSame('Kalem', $user->getPublicName());
    }

    public function testAccountNameIsSanitized(): void
    {
        $user = new User(new Email('writer@example.com'), 'hash', "  <b>Erhan</b>\n\n  Kaya  ");

        self::assertSame('Erhan Kaya', $user->getName());
        self::assertStringNotContainsString('<', $user->getName());
    }

    public function testDisplayNameIsStrippedAndCapped(): void
    {
        $user = new User(new Email('writer@example.com'), 'hash', 'Erhan');
        $user->setDisplayName('<script>alert(1)</script>' . str_repeat('a', 300));

        $displayName = (string) $user->getDisplayName();

        self::assertSame(255, mb_strlen($displayName));
        self::assertStringNotContainsString('<', $displayName);
        self::assertStringNotContainsString('script', mb_substr($displayName, 0, 10));
    }
}
