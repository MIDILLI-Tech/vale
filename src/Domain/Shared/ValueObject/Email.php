<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\ValueObject;

use ValeApp\Domain\Shared\Exception\ValidationException;

/**
 * Validated email address value object.
 */
final readonly class Email
{
    public string $value;

    public function __construct(string $value)
    {
        $value = mb_strtolower(trim($value));

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(
                ['email' => ['Invalid email address.']],
                'Invalid email address: ' . $value,
            );
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
