<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Shared\ValueObject;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Email;

final class EmailTest extends TestCase
{
    public function testValidEmail(): void
    {
        $email = new Email('TEST@Example.com ');
        self::assertSame('test@example.com', $email->value);
        self::assertSame('test@example.com', (string) $email);
    }

    public function testInvalidEmailThrowsException(): void
    {
        $this->expectException(ValidationException::class);
        new Email('invalid-email');
    }

    public function testEquals(): void
    {
        $email1 = new Email('test@example.com');
        $email2 = new Email('TEST@example.com');
        $email3 = new Email('other@example.com');

        self::assertTrue($email1->equals($email2));
        self::assertFalse($email1->equals($email3));
    }
}
