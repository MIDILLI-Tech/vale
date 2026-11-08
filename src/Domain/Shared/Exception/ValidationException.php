<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\Exception;

/**
 * Thrown when input validation fails.
 * Carries a map of field => [error messages].
 */
class ValidationException extends DomainException
{
    /** @var array<string, string[]> */
    private array $errors;

    /**
     * @param array<string, string[]> $errors Field-keyed error messages
     */
    public function __construct(array $errors, string $message = 'Validation failed.')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /** @return array<string, string[]> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /**
     * Create from a flat field => message map.
     *
     * @param array<string, string> $messages
     */
    public static function withMessages(array $messages): self
    {
        $errors = [];
        foreach ($messages as $field => $message) {
            $errors[$field] = [$message];
        }
        return new self($errors);
    }
}
