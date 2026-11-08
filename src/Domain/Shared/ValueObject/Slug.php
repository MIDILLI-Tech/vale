<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\ValueObject;

use ValeApp\Domain\Shared\Exception\DomainException;

/**
 * URL-safe slug value object.
 */
final readonly class Slug
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw new DomainException('Slug cannot be empty.');
        }

        // Allowed: lowercase alphanumeric, hyphens, forward slashes (for hierarchical slugs)
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/', $value)) {
            throw new DomainException('Invalid slug format: ' . $value);
        }

        $this->value = $value;
    }

    /**
     * Generate a slug from a human-readable string.
     */
    public static function fromString(string $text): self
    {
        $slug = mb_strtolower(trim($text));
        // Replace non-alphanumeric with hyphens
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug);
        // Collapse multiple hyphens
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'untitled';
        }

        return new self($slug);
    }

    /**
     * Append a numeric suffix to avoid duplicates (e.g. "my-post-2").
     */
    public function withSuffix(int $number): self
    {
        return new self($this->value . '-' . $number);
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
